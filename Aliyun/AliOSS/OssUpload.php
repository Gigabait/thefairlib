<?php

namespace TheFairLib\Aliyun\AliOSS;

use OSS\Core\OssException;

/**
 *  Demo
 * $file = new TheFairLib\Aliyun\AliOSS\Upload('file', [
 * "host" => Yaf\Registry::get('config')->static['cdn'],
 * "savePath" => Yaf\Registry::get('config')->cache['temp'],
 * "ossPath" => APP_NAME,
 * "maxSize" => 2000, //单位KB
 * "allowFiles" => [".gif", ".png", ".jpg", ".jpeg", ".bmp"]
 * ]);
 *
 * Class Upload
 * @package TheFairLib\Aliyun\AliOSS
 */
class OssUpload
{
    private $fileField;            //文件域名
    private $file;                 //文件上传对象
    private $config;               //配置信息
    private $oriName;              //原始文件名
    private $fileName;             //新文件名
    private $fullName;             //完整文件名,即从当前配置目录开始的URL
    private $newName;              //文件名
    private $ossPath;              //上传到阿里云OSS
    private $fileSize;             //文件大小
    private $fileType = '.png';    //文件类型
    private $imageInfo;            //图片信息
    private $stateInfo;            //上传状态信息,
    private $host;                 //服务器,
    private $bucket;               //
    private $stateMap = [    //上传状态映射表，国际化用户需考虑此处数据的国际化
        "success",                //上传成功标记，在UEditor中内不可改变，否则flash判断会出错
        "文件大小超出 upload_max_filesize 限制",
        "文件大小超出 MAX_FILE_SIZE 限制",
        "文件未被完整上传",
        "没有文件被上传",
        "上传文件为空",
        "POST" => "文件大小超出 post_max_size 限制",
        "SIZE" => "文件大小超出网站限制",
        "TYPE" => "不允许的文件类型",
        "DIR" => "目录创建失败",
        "IO" => "输入输出错误",
        "UNKNOWN" => "未知错误",
        "MOVE" => "文件保存时出错"
    ];

    /**
     * 构造函数
     * @param string $fileField 表单名称
     * @param array $config 配置项
     * @param string $bucket 存储名
     * @param bool $base64 是否解析base64编码，可省略。若开启，则$fileField代表的是base64编码的字符串表单名
     */
    public function __construct($fileField, $config, $bucket, $base64 = false)
    {
        $this->fileField = $fileField;
        $this->config = $config;
        $this->stateInfo = $this->stateMap[0];
        $this->bucket = $bucket;
        $this->_upFile($base64);
    }

    /**
     * 上传文件的主处理方法
     *
     * @param $base64
     * @throws \Exception
     */
    private function _upFile($base64)
    {
        //处理base64上传
        if ($base64) {
            $this->_base64ToImage($this->fileField);
            return;
        }

        //处理普通上传
        $file = $this->file = $_FILES[$this->fileField];
        if (!$file) {
            $this->stateInfo = $this->_getStateInfo('POST');
            return;
        }

        if ($this->file['error']) {
            $this->stateInfo = $this->_getStateInfo($file['error']);
            return;
        }

        $this->oriName = $file['name'];
        $this->fileSize = $file['size'];

        $this->fileType = $this->_getFileExt();

        if (!$this->_checkSize()) {
            $this->stateInfo = $this->_getStateInfo("SIZE");
            return;
        }

        if (!$this->_checkType()) {
            $this->stateInfo = $this->_getStateInfo("TYPE");
            return;
        }

        $this->newName = $this->_getName();
        $this->fullName = $this->_getFolder() . '/' . $this->newName . $this->_getFileExt();
        if ($this->stateInfo == $this->stateMap[0]) {

            if (is_uploaded_file($file['tmp_name'])) {
                if (!move_uploaded_file($file["tmp_name"], $this->fullName)) {
                    $this->stateInfo = $this->_getStateInfo("MOVE");
                    return;
                }
            } else {
                $this->fullName = $file['tmp_name'];
            }
            $this->uploadOSS();
        }
    }

    /**
     * 上传文件到阿里云中
     *
     * @param bool $base64
     * @throws \Exception
     */
    private function uploadOSS($base64 = false)
    {
        try {
            $ossClient = OssClient::Instance($this->bucket)->ossClient();
            $this->ossPath = $this->_getOssFolder();
            $path = $this->ossPath . DIRECTORY_SEPARATOR . $this->newName;  //创建路径加文件名
            $obj = $base64 ? $path . $this->fileType : $path . $this->_getFileExt();
            $ossClient->uploadFile($this->bucket, $obj, $this->fullName);
        } catch (OssException $e) {
            throw new \Exception("上传阿里云OSS文件失败：" . $e->getMessage());
        }
    }

    /**
     * 处理base64编码的图片上传
     *
     * @param $base64Data
     * @throws \Exception
     */
    private function _base64ToImage($base64Data)
    {
        if (strpos($base64Data, ',') !== false) {
            list($type, $img) = explode(',', $base64Data, 2);
            preg_match_all('/^data:image\/(\w+);base64$/', $type, $imgType);
            if (!empty($imgType[1][0])) {
                if (in_array($imgType[1][0], ['jpeg', 'jpg'])) {
                    $this->fileType = '.jpg';
                } else {
                    $this->fileType = '.' . $imgType[1][0];
                }
            }
            $img = base64_decode($img);
        } else {
            $img = base64_decode($base64Data);
        }
        $this->newName = md5(time() . rand(1, 10000));
        $this->fileName = $this->newName;
        $this->fullName = $this->_getFolder() . '/' . $this->fileName . $this->fileType;
        if (!in_array($this->fileType, $this->config["allowFiles"])) {
            $this->stateInfo = $this->_getStateInfo("TYPE");
            return;
        }
        if (!file_put_contents($this->fullName, $img)) {
            $this->stateInfo = $this->_getStateInfo("IO");
            return;
        }
        $this->fileSize = filesize($this->fullName);
        if (!$this->_checkSize()) {
            $this->stateInfo = $this->_getStateInfo("SIZE");
            return;
        }
        $this->uploadOSS(true);
        $this->oriName = "";
        $this->fileSize = strlen($img);
    }

    /**
     * 获得图片信息
     *
     */
    public function getImageInfo()
    {
        if (file_exists($this->fullName)) {
            if (function_exists('exif_imagetype')) {
                $imageType = exif_imagetype($this->fullName);//判断是否为图片
                $type = array(
                    IMAGETYPE_GIF => "gif",
                    IMAGETYPE_JPEG => "jpg",
                    IMAGETYPE_PNG => "png",
                    IMAGETYPE_SWF => "swf",
                    IMAGETYPE_PSD => "psd",
                    IMAGETYPE_BMP => "bmp",
                    IMAGETYPE_TIFF_II => "tiff",
                    IMAGETYPE_TIFF_MM => "tiff",
                    IMAGETYPE_JPC => "jpc",
                    IMAGETYPE_JP2 => "jp2",
                    IMAGETYPE_JPX => "jpx",
                    IMAGETYPE_JB2 => "jb2",
                    IMAGETYPE_SWC => "swc",
                    IMAGETYPE_IFF => "iff",
                    IMAGETYPE_WBMP => "wbmp",
                    IMAGETYPE_XBM => "xbm",
                    IMAGETYPE_ICO => "ico"
                );
                $info = getimagesize($this->fullName);
                if (!empty($info)) {
                    $this->imageInfo['scale'] = empty($info[0]) ? 0 : round($info[0] / $info[1], 2);
                    $this->imageInfo['width'] = $info[0];
                    $this->imageInfo['height'] = $info[1];
                }
                isset($type[$imageType]) ? $this->imageInfo : $this->imageInfo = 'not image';//获得图片大小信息
            }
        }

    }

    /**
     * 获取当前上传成功文件的各项信息
     * @return array
     */
    public function getFileInfo()
    {
        $type = isset($this->config['object_type']) ? $this->config['object_type'] : OssClient::$config['object_type'];

        $scheme = isset($this->config['scheme']) ? $this->config['scheme'] : OssClient::$config['scheme'];

        $domain = isset($this->config['domain']) ? $this->config['domain'] : 'thefair';

        $this->host = $scheme . '://' . OssClient::$config['domain'][$domain] . '/';

        $url = $this->host . $this->ossPath . '/' . $this->newName . $this->_getFileExt();

        $fileInfo = [
            'url' => $this->stateInfo === 'success' ? $url : '',
            'name' => $this->fileName,
            'size' => $this->fileSize,
            'type' => substr($this->fileType, 1),
            'state' => $this->stateInfo,
            'originalName' => $this->oriName,
            'file_type' => $type,
        ];
        switch ($type) {//image,file,video,audio
            case 'image' :
                $this->getImageInfo();
                $fileInfo['info'] = $this->imageInfo;
                break;
            case 'video' :
                $info = $this->getVideoInfo();
                $fileInfo = array_merge($fileInfo, $info);
                break;
            case 'audio' :
                $info = $this->getAudioInfo();
                $fileInfo = array_merge($fileInfo, $info);
                break;
        }
        $this->_rm();//删除本地文件
        return $fileInfo;
    }

    public function getAudioInfo()
    {
        return [// {ObjectPrefix}{FileName}/{FileName}
//            "url" => $this->host . $this->ossPath . '/' . $this->newName . '/' . $this->newName . '.m3u8',
            "url_m3u8" => $this->host . $this->ossPath . '/' . $this->newName . '/' . $this->newName . '.m3u8',
        ];
    }

    public function getVideoInfo()
    {
        return [// {ObjectPrefix}{FileName}/{FileName}
            "url_m3u8" => $this->host . $this->ossPath . '/' . $this->newName . '/' . $this->newName . '.m3u8',
            "url_mp4" => $this->host . $this->ossPath . '/' . $this->newName . '/' . $this->newName . '.mp4',
            "cover_img" => $this->host . $this->ossPath . '/' . $this->newName . '/' . $this->newName . '.jpg',
            "source_url" => str_replace($this->config['host'], $this->config['source_host'], $this->ossPath),
        ];
    }

    /**
     * 上传错误检查
     * @param $errCode
     * @return string
     */
    private function _getStateInfo($errCode)
    {
        return !$this->stateMap[$errCode] ? $this->stateMap["UNKNOWN"] : $this->stateMap[$errCode];
    }

    /**
     * 重命名文件
     * @return string
     */
    private function _getName()
    {
        return $this->fileName = md5(time() . rand(1, 10000));
    }

    /**
     * 文件类型检测
     * @return bool
     */
    private function _checkType()
    {
        return in_array($this->_getFileExt(), $this->config["allowFiles"]);
    }

    /**
     * 文件大小检测
     * @return bool
     */
    private function _checkSize()
    {
        return $this->fileSize <= ($this->config["maxSize"] * 1024);
    }

    /**
     * 获取文件扩展名
     * @return string
     */
    private function _getFileExt()
    {
        return strtolower(strrchr($this->file["name"], '.'));
    }

    /**
     * 删除文件
     * @return boolean
     */
    private function _rm()
    {
        if (file_exists($this->fullName)) {
            unlink($this->fullName);
            return true;
        }
        return false;
    }

    /**
     * 按照日期自动创建存储文件夹
     *
     * @return string
     * @throws \Exception
     */
    private function _getOssFolder()
    {
        $pathStr = $this->config["ossPath"];
        if (strrchr($pathStr, "/") != "/") {
            $pathStr .= "/";
        }
        $pathStr .= date("Ymd");
        try {
            OssClient::Instance($this->bucket)->ossClient()->createObjectDir($this->bucket, $pathStr);
        } catch (OssException $e) {
            throw new \Exception("自动创建存储文件夹失败：" . $e->getMessage());
        }
        return $pathStr;
    }

    /**
     * 按照日期自动创建存储文件夹
     * @return string
     */
    private function _getFolder()
    {
        $pathStr = $this->config["savePath"];
        if (strrchr($pathStr, "/") != "/") {
            $pathStr .= "/";
        }
        $pathStr .= date("Ymd");
        if (!file_exists($pathStr)) {
            if (!mkdir($pathStr, 0777, true)) {
                return false;
            }
        }
        return $pathStr;
    }
}
