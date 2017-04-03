<?php

/**
 * Implements the AWS extension for MediaWiki.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use Aws\S3\S3Client;
use Aws\S3\Exception\BucketNotEmptyException;
use Aws\S3\Exception\NoSuchBucketException;
use Aws\S3\Exception\NoSuchKeyException;
use Aws\S3\Exception\S3Exception;

/**
 * FileBackend for Amazon S3
 *
 * @author Tyler Romeo <tylerromeo@gmail.com>
 * @author Thai Phan <thai@outlook.com>
 */
class AmazonS3FileBackend extends FileBackendStore {
	/**
	 * Amazon S3 client object from the SDK
	 * @var Aws\S3\S3Client
	 */
	private $client;

	/**
	 * Whether server side encryption is enabled
	 * @var bool
	 */
	private $encryption;

	/**
	 * Whether to use HTTPS for communicating with Amazon
	 * @var bool
	 */
	private $useHTTPS;

	private $containerPaths;

	/**
	 * Construct the backend. Doesn't take any extra config parameters.
	 *
	 * The configuration array may contain the following keys in addition
	 * to the keys accepted by FileBackendStore::__construct:
	 *  * containerPaths (required) - Mapping of container names to Amazon S3 buckets
	 *                                Each container must have its own unique bucket
	 *  * awsKey - An AWS authentication key to override $wgAWSCredentials
	 *  * awsSecret - An AWS authentication secret to override $wgAWSCredentials
	 *  * awsRegion - Region to use in place of $wgAWSRegion
	 *  * awsHttps - Whether to use HTTPS for AWS connections (defaults to $wgAWSUseHTTPS)
	 *  * awsEncryption - Whether to turn on server-side encryption on AWS (implies awsHttps=true)
	 *
	 * @param array $config
	 * @throws MWException if no containerPaths is set
	 */
	function __construct( array $config ) {
		global $wgAWSCredentials, $wgAWSRegion, $wgAWSUseHTTPS;

		parent::__construct( $config );

		$this->encryption = isset( $config['awsEncryption'] ) ? (bool)$config['awsEncryption'] : true;

		if ( $this->encryption ) {
			$this->useHTTPS = true;
		} elseif ( isset( $config['awsHttps'] ) ) {
			$this->useHTTPS = (bool)$config['awsHttps'];
		} else {
			$this->useHTTPS = (bool)$wgAWSUseHTTPS;
		}

		// Cache container information to mask latency
		if ( isset( $config['wanCache'] ) && $config['wanCache'] instanceof WANObjectCache ) {
			$this->memCache = $config['wanCache'];
		}

		$key = isset($config['awsKey']) ? $config['awsKey'] : (isset($wgAWSCredentials['key']) ? $wgAWSCredentials['key'] : null);
		$secret = isset($config['awsSecret']) ? $config['awsSecret'] : (isset($wgAWSCredentials['secret']) ? $wgAWSCredentials['secret'] : null);
		$token = isset($config['awsToken']) ? $config['awsToken'] : (isset($wgAWSCredentials['token']) ? $wgAWSCredentials['token'] : null);

		$this->client = S3Client::factory( array(
			'key' => $key,
			'secret' => $secret,
			'token' => $token,
			'region' => isset( $config['awsRegion'] ) ? $config['awsRegion'] : $wgAWSRegion,
			'scheme' => $this->useHTTPS ? 'https' : 'http',
			'ssl.certificate_authority' => $this->useHTTPS ?: null,
			'version' => '2006-03-01'
		) );

		if ( isset( $config['containerPaths'] ) ) {
			$this->containerPaths = (array)$config['containerPaths'];
		} else {
			throw new MWException( __METHOD__ . " : containerPaths array must be set for S3." );
		}
	}

	function directoriesAreVirtual() {
		return true;
	}

	function isPathUsableInternal( $storagePath ) {
		list( $container, $rel ) = $this->resolveStoragePathReal( $storagePath );
		return $container !== null;
	}

	function resolveContainerName( $container ) {
		if (!isset($this->containerPaths[$container])) {
			return null;
		}
		list($bucket) = explode('/', $this->containerPaths[$container]);
		if (!$this->client->isBucketDnsCompatible($bucket)) {
			return null;
		}
		return $bucket;
	}

	function resolveContainerPath( $shortCont, $relStoragePath ) {
		$container = $this->fullContainerName( $shortCont );
		if (!isset($this->containerPaths[$container])) {
			return null;
		}
		if ( strlen( urlencode( $relStoragePath ) ) > 1024 ) {
			return null;
		}
		$basePath = '';
		$pos = strpos($this->containerPaths[$container], '/');
		if ($pos !== false) {
			$basePath = substr($this->containerPaths[$container], $pos+1);
		}
		return "$basePath/$relStoragePath";
	}

	function doCreateInternal( array $params ) {
		$status = Status::newGood();

		list( $container, $key ) = $this->resolveStoragePathReal( $params['dst'] );
		if( $container === null || $key == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );
			return $status;
		}

		if ( is_resource( $params['content'] ) ) {
			$sha1Hash = Wikimedia\base_convert( sha1_file( $params['src'] ), 16, 36, 31 );
		} else {
			$sha1Hash = Wikimedia\base_convert( sha1( $params['content'] ), 16, 36, 31 );
		}

		$params['headers'] = isset( $params['headers'] ) ? $params['headers'] : array();
		$params['headers'] += array_fill_keys( array(
			'Cache-Control',
			'Content-Disposition',
			'Content-Encoding',
			'Content-Language',
			'Expires'
		), null );

		if ( !isset( $params['headers']['Content-Type'] ) ) {
			$params['headers']['Content-Type'] = $this->getContentType( $params['dst'], $params['content'], null );
		}

		try {
			$apiParams = [
				'Body' => $params['content'],
				'Bucket' => $container,
				'CacheControl' => $params['headers']['Cache-Control'],
				'ContentDisposition' => $params['headers']['Content-Disposition'],
				'ContentEncoding' => $params['headers']['Content-Encoding'],
				'ContentLanguage' => $params['headers']['Content-Language'],
				'ContentType' => $params['headers']['Content-Type'],
				'Expires' => $params['headers']['Expires'],
				'Key' => $key,
				'Metadata' => array( 'sha1base36' => $sha1Hash ),
				'ServerSideEncryption' => $this->encryption ? 'AES256' : null,
			];
			// passing null params breaks AWS client
			foreach ($apiParams as $k => $v) {
				if ($v === null) {
					unset($apiParams[$k]);
				}
			}
			$res = $this->client->putObject($apiParams);
		} catch ( NoSuchBucketException $e ) {
			$status->fatal( 'backend-fail-create', $params['dst'] );
		} catch ( S3Exception $e ) {
			$this->handleException( $e, $status, __METHOD__, $params );
		}

		return $status;
	}

	function doStoreInternal( array $params ) {
		$params['content'] = fopen( $params['src'], 'r' );
		return $this->doCreateInternal( $params );
	}

	function doCopyInternal( array $params ) {
		$status = Status::newGood();

		list( $srcContainer, $srcKey ) = $this->resolveStoragePathReal( $params['src'] );
		list( $dstContainer, $dstKey ) = $this->resolveStoragePathReal( $params['dst'] );
		if( $srcContainer === null || $srcKey == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
		}
		if( $dstContainer === null || $dstKey == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );
		}

		if( !$status->isOK() ) {
			return $status;
		}

		$params['headers'] = isset( $params['headers'] ) ? $params['headers'] : array();
		$params['headers'] += array_fill_keys( array(
			'Cache-Control',
			'Content-Disposition',
			'Content-Encoding',
			'Content-Language',
			'Content-Type',
			'Expires',
			'E-Tag',
			'If-Modified-Since'
		), null );

		try {
			$res = $this->client->copyObject( array_filter( array(
				'Bucket' => $dstContainer,
				'CacheControl' => $params['headers']['Cache-Control'],
				'ContentDisposition' => $params['headers']['Content-Disposition'],
				'ContentEncoding' => $params['headers']['Content-Encoding'],
				'ContentLanguage' => $params['headers']['Content-Language'],
				'ContentType' => $params['headers']['Content-Type'],
				'CopySource' => $srcContainer . '/' . $this->client->encodeKey( $srcKey ),
				'CopySourceIfMatch' => $params['headers']['E-Tag'],
				'CopySourceIfModifiedSince' => $params['headers']['If-Modified-Since'],
				'Expires' => $params['headers']['Expires'],
				'Key' => $dstKey,
				'MetadataDirective' => 'COPY',
				'ServerSideEncryption' => $this->encryption ? 'AES256' : null
			) ) );
		} catch ( NoSuchBucketException $e ) {
			$status->fatal( 'backend-fail-copy', $params['src'], $params['dst'] );
		} catch ( NoSuchKeyException $e ) {
			if ( empty( $params['ignoreMissingSource'] ) ) {
				$status->fatal( 'backend-fail-copy', $params['src'], $params['dst'] );
			}
		} catch ( S3Exception $e ) {
			$this->handleException( $e, $status, __METHOD__, $params );
		}

		return $status;
	}

	function doDeleteInternal( array $params ) {
		$status = Status::newGood();

		list( $container, $key ) = $this->resolveStoragePathReal( $params['src'] );
		if( $container === null || $key == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
			return $status;
		}

		try {
			$this->client->deleteObject( array(
				'Bucket' => $container,
				'Key' => $key
			) );
		} catch ( NoSuchBucketException $e ) {
			$status->fatal( 'backend-fail-delete', $params['src'] );
		} catch ( NoSuchKeyException $e ) {
			if ( empty( $params['ignoreMissingSource'] ) ) {
				$status->fatal( 'backend-fail-delete', $params['src'] );
			}
		} catch ( S3Exception $e ) {
			$this->handleException( $e, $status, __METHOD__, $params );
		}

		return $status;
	}

	function doDirectoryExists( $container, $dir, array $params ) {
		return true;
	}

	function doGetFileStat( array $params ) {
		list( $container, $key ) = $this->resolveStoragePathReal( $params['src'] );

		if( $container === null || $key == null ) {
			return null;
		} elseif( !$this->client->doesBucketExist( $container ) ) {
			return false;
		} elseif( !$this->client->doesObjectExist( $container, $key ) ) {
			return false;
		}

		try {
			$res = $this->client->headObject( array(
				'Bucket' => $container,
				'Key' => $key
			) );
		} catch ( S3Exception $e ) {
			$this->handleException( $e, null, __METHOD__, $params );
			return false;
		}

		return array(
			'mtime' => wfTimestamp( TS_MW, $res['LastModified'] ),
			'size' => (int)$res['ContentLength'],
			'etag' => $res['Etag'],
			'sha1' => $res['Metadata']['sha1base36']
		);
	}

	function doGetFileSha1base36( array $params ) {
		$stat = $this->getFileStat( $params );
		return $stat ? $stat['sha1'] : false;
	}

	function getFileHttpUrl( array $params ) {
		list( $container, $key ) = $this->resolveStoragePathReal( $params['src'] );
		if( $container === null ) {
			return null;
		}
		return $this->client->getObjectUrl($container, $key);
	}

	function getDirectoryListInternal( $container, $dir, array $params ) {
		return new AmazonS3DirectoryIterator( $this->client, $container, $dir, $params );
	}

	function getFileListInternal( $container, $dir, array $params ) {
		return new AmazonS3FileIterator( $this->client, $container, $dir, $params );
	}

	function doGetLocalCopyMulti( array $params ) {
		$fsFiles = array();
		$params += array(
			'srcs' => $params['src'],
			'concurrency' => isset( $params['srcs'] ) ? count( $params['srcs'] ) : 1
		);
		foreach( array_chunk( $params['srcs'], $params['concurrency'] ) as $pathBatch ) {
			foreach( $pathBatch as $src ) {
				list( $container, $key ) = $this->resolveStoragePathReal( $src );
				if( $container === null || $key === null ) {
					$fsFiles[$src] = null;
					continue;
				}

				$ext = self::extensionFromPath( $src );
				$tmpFile = TempFSFile::factory( 'localcopy_', $ext );
				if( !$tmpFile ) {
					$fsFiles[$src] = null;
					continue;
				}

				$srcPath = $this->getFileHttpUrl( array( 'src' => $src ) );
				$dstPath = $tmpFile->getPath();
				if( !$srcPath ) {
					$fsFiles[$src] = null;
					continue;
				}

				wfSuppressWarnings();
				$ok = copy( $srcPath, $dstPath );
				wfRestoreWarnings();

				$fsFiles[$src] = $ok ? $tmpFile : null;
			}
		}
		return $fsFiles;
	}


	function doPrepareInternal( $container, $dir, array $params ) {
		return Status::newGood();;
	}

	function doCleanInternal( $container, $dir, array $params ) {
		return Status::newGood();  /* Nothing to do */
	}

	function doPublishInternal( $container, $dir, array $params ) {
		return Status::newGood();;
	}

	function doSecureInternal( $container, $dir, array $params ) {
		return Status::newGood();;
	}

	/**
	 * Handle an unknown S3Exception by adjusting the status and triggering an error.
	 *
	 * @param Aws\S3\Exception\S3Exception $e Exception that was thrown
	 * @param Status $status Status object for the operation
	 * @param string $func Function in which the exception occurred
	 * @param array $params Params passed to the function
	 */
	private function handleException( S3Exception $e, Status $status, $func, array $params ) {
		$status->fatal( 'backend-fail-internal', $this->name );
		if ( $e->getMessage() ) {
			trigger_error( "$func : {$e->getMessage()}", E_USER_WARNING );
		}
		wfDebugLog( 'S3Backend',
			get_class( $e ) . "in '{$func}' (given '" . FormatJson::encode( $params ) . "')" .
			( $e->getMessage() ? ": {$e->getMessage()}" : "" )
		);
	}
}

class AmazonS3FileIterator implements Iterator {
	private $client, $container, $dir, $topOnly, $limit;
	private $index, $results, $marker, $finished;

	private $suffixStart;

	public function __construct( S3Client $client, $container, $dir, array $params, $limit = 500 ) {
		/* "Directory" must end with the slash,
			otherwise S3 will return PRE (prefix) suggestion
			instead of the listing itself */
		if(substr($dir, -1, 1) != '/') {
			$dir .= '/';
		}

		$this->suffixStart = strlen($dir); // size of "path/to/dir/"

		$this->client = $client;
		$this->container = $container;
		$this->dir = $dir;
		$this->limit = $limit;
		$this->topOnly = !empty( $params['topOnly'] );

		$this->rewind();
	}

	public function key() {
		$this->init();
		return $this->index;
	}

	public function current() {
		$this->init();
		return substr( $this->results['Contents'][$this->index]['Key'], $this->suffixStart );
	}

	public function next() {
		if( $this->topOnly ) {
			do {
				++$this->index;
			} while( strpos( $this->current(), '/', strlen( $this->dir ) ) !== false );
		} else {
			++$this->index;
		}
	}

	public function rewind() {
		$this->results = null;
		$this->marker = null;
		$this->index = 0;
		$this->finished = false;
	}

	public function valid() {
		$this->init();
		return !$this->finished || $this->index < count( $this->results['Contents'] );
	}

	private function init() {
		if(
			(
				$this->results === null ||
				$this->index >= count( $this->results['Contents'] )
			) &&
			!$this->finished
		) {
			try {
				$this->results = $this->client->listObjects( array(
					'Bucket' => $this->container,
					'Delimiter' => '/',
					'Marker' => $this->marker,
					'MaxKeys' => $this->limit,
					'Prefix' => $this->dir
				) );
			} catch ( NoSuchBucketException $e ) {
				$this->results = array(
					'Marker' => null,
					'IsTruncated' => false,
					'Contents' => array()
				);
				$this->finished = true;
			}

			$this->index = 0;
			$this->marker = $this->results['Marker'];
			$this->finished = !$this->results['IsTruncated'];
			return true;
		} else {
			return false;
		}
	}
}

class AmazonS3DirectoryIterator extends AmazonS3FileIterator {
	private $directories = array();

	function current() {
		return dirname( parent::current() );
	}

	function next() {
		do {
			parent::next();
		} while( array_key_exists( $this->current(), $this->directories ) );
		$this->directories[] = $this->current();
	}
}
