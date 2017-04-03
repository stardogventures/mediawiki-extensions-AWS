# mediawiki-extensions-AWS (Stardog Ventures fork)

This fork to the Mediawiki AWS extension is intended to support a few different features aimed at running Mediawiki on an EC2 instance and uploading images to S3:

- The original extension requires you to use an AWS key and secret, meaning you cannot make use of EC2 IAM roles. This fork allows you to set key and secret to null, meaning the AWS SDK will load credentials from the EC2 metadata.
- The original extension uses an earlier version of the AWS PHP client library. This version uses a newer version.
- The original extension requires you to use four different S3 buckets. This version allows you
to use a single bucket if you choose, by specifying a path in `$wgFileBackends['s3']['containerPaths']`
- The original extension creates buckets on the fly if they don't exist and sets ACLs on a per-object basis. This fork assumes you will set up your buckets and permissions yourself (and issue your EC2 instance least privileges), so this functionality is removed.

To install, copy the contents of the repo into `wiki-install-dir/extensions/AWS`

Then `composer install` and `composer require wikimedia/base-convert`

Modify your `LocalSettings.php` to include the extension, for example:

```php
$wgEnableUploads = true;

require_once("$IP/extensions/AWS/AWS.php");
$wgAWSCredentials = ['key' => null, 'secret' => null];
$wgAWSRegion = '{{aws_region}}';
$wgFileBackends['s3']['containerPaths'] = [
	'wiki-local-public' => 's3-bucket-name/wiki/public',
	'wiki-local-thumb' => 's3-bucket-name/wiki/thumb',
	'wiki-local-deleted' => 's3-bucket-name/wiki/deleted',
	'wiki-local-temp' => 's3-bucket-name/wiki/temp',
];
$wgLocalFileRepo = [
	'class' => 'LocalRepo',
	'name' => 'local',
	'backend' => 'AmazonS3',
	'scriptDirUrl' => $wgScriptPath,
	'scriptExtension' => $wgScriptExtension,
	'url' => $wgScriptPath . 'img_auth.php',
	'zones' => [
		'public' => ['url' => 'https://cloudfront.example.com/wiki/public'],
		'thumb' => ['url' => 'https://cloudfront.example.com/wiki/thumb'],
		'deleted' => ['url' => 'https://cloudfront.example.com/wiki/deleted'],
	]
];
```
