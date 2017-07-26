<?php
namespace Kof\Utils;

use Psr\Http\Message\UploadedFileInterface;
use Phalcon\Validation;
use Phalcon\Validation\Validator\InclusionIn as InclusionInValidator;
use Kof\Phalcon\Validation\Validator\MaxSize as MaxSizeValidator;
use InvalidArgumentException;
use RuntimeException;

class Uploader
{
    protected $_options;

    protected $_validationOptions;

    protected $_uploadedFiles;

    public function __construct($options = [], array $validationOptions = [])
    {
        $this->_options = $options;
        $this->_validationOptions = $validationOptions;
        $this->_uploadedFiles = $this->normalizeFiles($_FILES);
    }

    public function getOption($key = null, $default = null)
    {
        if ($key === null) {
            return $this->_options;
        }

        return isset($this->_options[$key]) ? $this->_options[$key] : $default;
    }

    /**
     * @param string|array|UploadedFileInterface $file
     * @param array $validationOptions
     * @throws RuntimeException if the upload was not successful.
     * @throws InvalidArgumentException if the $path specified is invalid.
     * @throws RuntimeException on any error during the move operation, or on
     *     the second or subsequent call to the method.
     * @return array
     */
    public function move($file, array $validationOptions = [])
    {
        if ($file instanceof UploadedFileInterface) {
            $uploadedFiles = [$file];
            $isMultiple = false;
        } elseif (is_array($file)) {
            $uploadedFiles = $file;
            $isMultiple = true;
        } else {
            if (!isset($this->_uploadedFiles[$file]) || empty($this->_uploadedFiles[$file])) {
                throw new RuntimeException("Field {$file} must not be empty");
            }
            $uploadedFiles = $this->_uploadedFiles[$file];
            $isMultiple = true;
            if (!is_array($uploadedFiles)) {
                $uploadedFiles = [$uploadedFiles];
                $isMultiple = false;
            }
        }

        // validate
        $validationOptions = array_merge($this->_validationOptions, $validationOptions);
        $iteriter = new \RecursiveIteratorIterator(
            new \RecursiveArrayIterator($uploadedFiles),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iteriter as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                switch ($value->getError()) {
                    case \UPLOAD_ERR_INI_SIZE:
                    case \UPLOAD_ERR_FORM_SIZE:
                        throw new RuntimeException('上传文件超出限制大小');
                        break;
                    case \UPLOAD_ERR_PARTIAL:
                        throw new RuntimeException('上传文件不完整');
                    case \UPLOAD_ERR_NO_FILE:
                        throw new RuntimeException('上传文件为空');
                        break;
                    case \UPLOAD_ERR_NO_TMP_DIR:
                    case \UPLOAD_ERR_CANT_WRITE:
                        throw new RuntimeException('上传目录不可写');
                        break;
                    case \UPLOAD_ERR_EXTENSION:
                        throw new RuntimeException('上传文件后缀名错误');
                        break;
                }

                if (isset($validationOptions['maxSize'])) {
                    $validation = new Validation();
                    $validationKey = $value->getClientFilename();
                    !$validationKey && $validationKey = 'file';
                    $validation->add($validationKey, new MaxSizeValidator([
                        'maxSize' => $validationOptions['maxSize']
                    ]));
                    $messages = $validation->validate([$validationKey => $value->getSize()]);
                    if (count($messages)) {
                        $messagesStrArr = [];
                        foreach ($messages as $message) {
                            $messagesStrArr[] = $message->getMessage();
                        }
                        throw new RuntimeException(implode("\n", $messagesStrArr));
                    }
                }

                if (isset($validationOptions['allowedTypes']) &&
                    is_array($validationOptions['allowedTypes']) &&
                    !empty($validationOptions['allowedTypes'])
                ) {
                    $validation = new Validation();
                    $validationKey = $value->getClientFilename();
                    !$validationKey && $validationKey = 'file';
                    $validation->add($validationKey, new InclusionInValidator([
                        'message' => 'File :field must be of type: :domain',
                        'domain' => $validationOptions['allowedTypes']
                    ]));
                    $messages = $validation->validate([$validationKey => $value->getClientMediaType()]);
                    if (count($messages)) {
                        $messagesStrArr = [];
                        foreach ($messages as $message) {
                            $messagesStrArr[] = $message->getMessage();
                        }
                        throw new RuntimeException(implode("\n", $messagesStrArr));
                    }
                }
            }
        }

        $results = $this->_doMove($uploadedFiles);

        return $isMultiple ? $results : $results[0];
    }

    /**
     * @param array $uploadedFiles
     * @return bool|array
     */
    protected function _doMove($uploadedFiles)
    {
        $results = [];
        $adapter = $this->getOption('adapter', 'Local');
        foreach ($uploadedFiles as $key => $uploadedFile) {
            if ($uploadedFile instanceof UploadedFileInterface) {
                $targetPath = uniqid() . '.' . self::getExtensionByMimetype($uploadedFile->getClientMediaType());
                if ($adapter == 'Local') {
                    $ttargetPath = $this->getOption('basePath', 'upload') . '/' . date("Y") . '/' . date("m") . '/' .
                        date("d");
                    if (!is_dir($ttargetPath)) {
                        mkdir($ttargetPath, 0777, 1);
                        chmod($ttargetPath, 0777);
                    }
                    $targetPath = $ttargetPath . '/' . $targetPath;
                    unset($ttargetPath);
                }
                $uploadedFile->moveTo($targetPath);
                $results[$key] = [
                    'adapter' => $this->getOption('adapter'),
                    'domain' => $this->getOption('domain'),
                    'path' => $targetPath,
                    'name' => $uploadedFile->getClientFilename(),
                    'size' => $uploadedFile->getSize(),
                    'type' => $uploadedFile->getClientMediaType()
                ];
            } else {
                $results[$key] = $this->_doMove($uploadedFile);
            }
        }

        return $results;
    }

    /**
     * Return an UploadedFile instance array.
     *
     * @param array $files A array which respect $_FILES structure
     * @throws InvalidArgumentException for unrecognized values
     * @return array
     */
    public function normalizeFiles(array $files)
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;
            } elseif (is_array($value) && isset($value['tmp_name'])) {
                $normalized[$key] = $this->createUploadedFileFromSpec($value);
            } elseif (is_array($value)) {
                $normalized[$key] = $this->normalizeFiles($value);
                continue;
            } else {
                throw new InvalidArgumentException('Invalid value in files specification');
            }
        }

        return $normalized;
    }

    /**
     * Create and return an UploadedFile instance from a $_FILES specification.
     *
     * If the specification represents an array of values, this method will
     * delegate to normalizeNestedFileSpec() and return that return value.
     *
     * @param array $value $_FILES struct
     * @return array|UploadedFileInterface
     */
    public function createUploadedFileFromSpec(array $value)
    {
        if (is_array($value['tmp_name'])) {
            return self::normalizeNestedFileSpec($value);
        }

        $adapter = $this->getOption('adapter', 'Local');
        $uploadedFileClassName = "Kof\\Psr7\\{$adapter}UploadedFile";

        $uploadedFile =  new $uploadedFileClassName(
            $value['tmp_name'],
            (int) $value['size'],
            (int) $value['error'],
            $value['name'],
            $value['type'],
            $this->_options
        );

        return $uploadedFile;
    }

    /**
     * Normalize an array of file specifications.
     *
     * Loops through all nested files and returns a normalized array of
     * UploadedFileInterface instances.
     *
     * @param array $files
     * @return UploadedFileInterface[]
     */
    public function normalizeNestedFileSpec(array $files = [])
    {
        $normalizedFiles = [];

        foreach (array_keys($files['tmp_name']) as $key) {
            $spec = [
                'tmp_name' => $files['tmp_name'][$key],
                'size'     => $files['size'][$key],
                'error'    => $files['error'][$key],
                'name'     => $files['name'][$key],
                'type'     => $files['type'][$key],
            ];
            $normalizedFiles[$key] = $this->createUploadedFileFromSpec($spec);
        }

        return $normalizedFiles;
    }

    /**
     * Maps a mimetype to a file extensions.
     *
     * @param $mimetype string The mimetype.
     *
     * @return string|null
     * @link http://svn.apache.org/repos/asf/httpd/httpd/branches/1.3.x/conf/mime.types
     */
    public static function getExtensionByMimetype($mimetype)
    {
        static $extensions = array (
            'application/x-7z-compressed' => '7z', 'audio/x-aac' => 'aac', 'application/postscript' => 'ps',
            'audio/x-aiff' => 'aif', 'text/plain' => 'txt', 'video/x-ms-asf' => 'asf',
            'application/atom+xml' => 'atom', 'video/x-msvideo' => 'avi', 'image/bmp' => 'bmp',
            'application/x-bzip2' => 'bz2', 'application/pkix-cert' => 'cer', 'application/pkix-crl' => 'crl',
            'application/x-x509-ca-cert' => 'crt', 'text/css' => 'css', 'text/csv' => 'csv',
            'application/cu-seeme' => 'cu', 'application/x-debian-package' => 'deb', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/x-dvi' => 'dvi', 'application/vnd.ms-fontobject' => 'eot', 'application/epub+zip' => 'epub',
            'text/x-setext' => 'etx', 'audio/flac' => 'flac', 'video/x-flv' => 'flv', 'image/gif' => 'gif',
            'application/gzip' => 'gz', 'text/html' => 'html', 'image/x-icon' => 'ico', 'text/calendar' => 'ics',
            'application/x-iso9660-image' => 'iso', 'application/java-archive' => 'jar', 'image/jpeg' => 'jpg',
            'text/javascript' => 'js', 'application/json' => 'json', 'application/x-latex' => 'latex',
            'audio/mp4' => 'mp4a', 'video/mp4' => 'mpg4', 'audio/midi' => 'midi', 'video/quicktime' => 'qt',
            'audio/mpeg' => 'mp3', 'video/mpeg' => 'mpg', 'audio/ogg' => 'ogg', 'video/ogg' => 'ogv',
            'application/ogg' => 'ogx', 'image/x-portable-bitmap' => 'pbm', 'application/pdf' => 'pdf',
            'image/x-portable-graymap' => 'pgm', 'image/png' => 'png', 'image/x-portable-anymap' => 'pnm',
            'image/x-portable-pixmap' => 'ppm', 'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/x-rar-compressed' => 'rar', 'image/x-cmu-raster' => 'ras', 'application/rss+xml' => 'rss',
            'application/rtf' => 'rtf', 'text/sgml' => 'sgml', 'image/svg+xml' => 'svg',
            'application/x-shockwave-flash' => 'swf', 'application/x-tar' => 'tar', 'image/tiff' => 'tiff',
            'application/x-bittorrent' => 'torrent', 'application/x-font-ttf' => 'ttf', 'audio/x-wav' => 'wav',
            'video/webm' => 'webm', 'audio/x-ms-wma' => 'wma', 'video/x-ms-wmv' => 'wmv',
            'application/x-font-woff' => 'woff', 'application/wsdl+xml' => 'wsdl', 'image/x-xbitmap' => 'xbm',
            'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/xml' => 'xml', 'image/x-xpixmap' => 'xpm', 'image/x-xwindowdump' => 'xwd', 'text/yaml' => 'yml',
            'application/zip' => 'zip',
        );

        $mimetype = strtolower($mimetype);

        return isset($extensions[$mimetype]) ? $extensions[$mimetype] : null;
    }
}
