<?php
namespace Aws\S3;

use Aws\AwsClient;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\Multipart\AbstractTransfer as AbstractMulti;
use GuzzleHttp\Collection;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Stream;
use GuzzleHttp\Command\CommandInterface;

/**
 * Client to interact with Amazon Simple Storage Service.
 */
class S3Client extends AwsClient
{
    /**
     * Determine if a string is a valid name for a DNS compatible Amazon S3
     * bucket.
     *
     * DNS compatible bucket names can be used as a subdomain in a URL (e.g.,
     * "<bucket>.s3.amazonaws.com").
     *
     * @param string $bucket Bucket name to check.
     *
     * @return bool
     */
    public static function isBucketDnsCompatible($bucket)
    {
        $bucketLen = strlen($bucket);

        return ($bucketLen >= 3 && $bucketLen <= 63) &&
            // Cannot look like an IP address
            !filter_var($bucket, FILTER_VALIDATE_IP) &&
            preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $bucket);
    }

    /**
     * Create a pre-signed URL for a request.
     *
     * @param RequestInterface $request     Request to generate the URL for.
     *                                      Use the factory methods of the
     *                                      client to create this request object
     * @param int|string|\DateTime $expires The time at which the URL should
     *                                      expire. This can be a Unix
     *                                      timestamp, a PHP DateTime object,
     *                                      or a string that can be evaluated
     *                                      by strtotime
     * @return string
     */
    public function createPresignedUrl(RequestInterface $request, $expires)
    {
        return $this->getSignature()->createPresignedUrl(
            $request,
            $this->getCredentials(),
            $expires
        );
    }

    /**
     * Returns the URL to an object identified by its bucket and key.
     *
     * If an expiration time is provided, the URL will be signed and set to
     * expire at the provided time.
     *
     * @param string $bucket  The name of the bucket where the object is located
     * @param string $key     The key of the object
     * @param mixed  $expires The time at which the URL should expire
     * @param array  $args    Arguments to the GetObject command. Additionally
     *                        you can specify a "Scheme" if you would like the
     *                        URL to use a different scheme than what the
     *                        client is configured to use
     *
     * @return string The URL to the object
     */
    public function getObjectUrl(
        $bucket,
        $key,
        $expires = null,
        array $args = []
    ) {
        $args = ['Bucket' => $bucket, 'Key' => $key] + $args;
        $command = $this->getCommand('GetObject', $args);
        $request = $command::createRequest($this, $command);

        if (isset($command['Scheme'])) {
            $request->setScheme($command['Scheme']);
        }

        return $expires
            ? $this->createPresignedUrl($request, $expires)
            : $request->getUrl();
    }

    /**
     * Determines whether or not a bucket exists by name.
     *
     * @param string $bucket  The name of the bucket
     *
     * @return bool
     */
    public function doesBucketExist($bucket)
    {
        return $this->checkExistenceWithCommand(
            $this->getCommand('HeadBucket', ['Bucket' => $bucket])
        );
    }

    /**
     * Determines whether or not an object exists by name.
     *
     * @param string $bucket  The name of the bucket
     * @param string $key     The key of the object
     * @param array  $options Additional options available in the HeadObject
     *                        operation (e.g., VersionId).
     *
     * @return bool
     */
    public function doesObjectExist($bucket, $key, array $options = [])
    {
        return $this->checkExistenceWithCommand(
            $this->getCommand('HeadObject', [
                'Bucket' => $bucket,
                'Key'    => $key
            ] + $options)
        );
    }

    /**
     * Raw URL encode a key and allow for '/' characters
     *
     * @param string $key Key to encode
     *
     * @return string Returns the encoded key
     */
    public static function encodeKey($key)
    {
        return str_replace('%2F', '/', rawurlencode($key));
    }

    /**
     * Register the Amazon S3 stream wrapper with this client instance.
     */
    public function registerStreamWrapper()
    {
        StreamWrapper::register($this);
    }

    /**
     * Upload a file, stream, or string to a bucket.
     *
     * If the upload size exceeds the specified threshold, the upload will be
     * performed using parallel multipart uploads.
     *
     * @param string $bucket  Bucket to upload the object
     * @param string $key     Key of the object
     * @param mixed  $body    Object data to upload. Can be a
     *                        GuzzleHttp\Stream\StreamInterface, PHP stream
     *                        resource, or a string of data to upload.
     * @param string $acl     ACL to apply to the object
     * @param array  $options Custom options used when executing commands:
     *
     *     - params: Custom parameters to use with the upload. The parameters
     *       must map to the parameters specified in the PutObject operation.
     *     - min_part_size: Minimum size to allow for each uploaded part when
     *       performing a multipart upload.
     *     - concurrency: Maximum number of concurrent multipart uploads.
     *     - before_upload: Callback to invoke before each multipart upload.
     *       The callback will receive a relevant Guzzle Event object.
     *
     * @see Aws\S3\Model\MultipartUpload\UploadBuilder for more information.
     * @return Result Returns the modeled result of the performed operation.
     */
    public function upload(
        $bucket,
        $key,
        $body,
        $acl = 'private',
        array $options = []
    ) {
        $body = Stream\create($body);
        $options = Collection::fromConfig($options, [
            'min_part_size' => AbstractMulti::MIN_PART_SIZE,
            'params'        => []
        ]);

        if ($body->getSize() < $options['min_part_size']) {
            // Perform a simple PutObject operation
            return $this->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => $body,
                'ACL'    => $acl
            ] + $options['params']);
        } else {
            // @todo
        }
    }

    /**
     * Determines whether or not a resource exists using a command
     *
     * @param CommandInterface $command Command used to poll for the resource
     *
     * @return bool
     * @throws S3Exception|\Exception if there is an unhandled exception
     */
    private function checkExistenceWithCommand(CommandInterface $command)
    {
        try {
            $this->execute($command);
            return true;
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() == 'AccessDenied') {
                return true;
            }
            if ($e->getStatusCode() >= 500) {
                throw $e;
            }
            return false;
        }
    }

    /** @deprecated */
    public function uploadDirectory()
    {
        $this->syncProxy();
    }

    /** @deprecated */
    public function downloadBucket()
    {
        $this->syncProxy();
    }

    private function syncProxy()
    {
        throw new \RuntimeException("uploadDirectory() and downloadBucket() "
            . "have been moved into a separate project: aws/s3-sync.");
    }

    /**
     * @deprecated This method is deprecated. Use Aws\S3\ClearBucket directly.
     */
    public function clearBucket($bucket)
    {
        (new ClearBucket($this, $bucket))->clear();
    }

    /**
     * @deprecated Use Aws\S3\S3Client::isBucketDnsCompatible() directly
     */
    public static function isValidBucketName($bucket)
    {
        return self::isBucketDnsCompatible($bucket);
    }
}
