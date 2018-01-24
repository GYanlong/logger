<?php
/**
 * @author:GYanlong
 * @Date: 2018/01/24
 */
class Logger
{
    const READ_FILE_BY_LINE = 1;

    const READ_FILE_BY_LENGTH = 2;

    /**
     * 日志存储目录
     */
    private $log_dir;
    /**
     * 成功日志文件名
     */
    private $success_log;

    /**
     * 失败日志文件名
     */
    private $failure_log;

    /**
     * 退出日志文件名
     */
    private $exit_log;

    /**
     * 文件名前缀
     */
    private $prefix;

    /**
     * 日志文件存储路径
     */
    private $path;

    /**
     *日志文件存储分隔符
     */
    private $delimiter;

    /**
     * 读取文件的模式，以行读取或按长度读取
     */
    private $read_mode = 1;

    /**
     * 读取长度
     */
    private $read_length = 2048;

    /**
     * 存储的数据类型，目前仅支持int,json,csv格式的支持
     */
    private $data_type;

    /**
     * 支持的数据类型
     */
    private $support_data_type = ['int', 'json', 'csv'];

    /**
     * 记录成功的次数
     */
    private $success_count = 0;

    /**
     * 记录失败的数据
     */
    private $failure_count = 0;

    /**
     * 记录总数量
     */
    private $count = 0;

    /**
     * 读取起点
     */
    private $_offset = [];

    /**
     * 保存文件指针
     */
    private $_pointers = [];

    /**
     * 最大读取量
     */
    private $max_read = 1000;

    /**
     * 记录开始时间
     */
    private $_start_time = 0;

    /**
     * 记录结束时间
     */
    private $_end_time = 0;

    /**
     * 缓存上次未解包的内容，防止内容丢失
     */
    private $remain = [];

    /**
     * 标准输出文件
     */
    public $stdoutFile = '/dev/null';

    /**
     * 是否已经展示程序运行结果
     */
    protected $is_displayed = false;

    /**
     * 记录上一次运行的信息
     */
    private $last_success;

    private $last_failure;

    private $last_exit;

    public function __construct($log_dir)
    {
        if (!is_dir($log_dir) || is_writable($log_dir)) {
            echo "Error : error log dir\n";
            exit;
        }
        $this->log_dir = $log_dir;
    }

    /**
     * init
     * @param $prefix string
     * @param $data_type string
     * @param $stdout_file string
     */
    public function init($prefix, $data_type = 'int', $stdout_file = '')
    {
        if ($prefix == '' || $prefix == '/')
            exit("Error : error $prefix\n");

        if (!in_array($data_type, $this->support_data_type))
            exit("Error : error not support data type");

        $this->prefix = $prefix;
        $this->success_log = $this->log_dir . $prefix . '_success.log';
        $this->failure_log = $this->log_dir . $prefix . '_failure.log';
        $this->exit_log = $this->log_dir . $prefix . '_exit.log';
        $this->data_type = $data_type;
        $this->delimiter = "\n";
        $this->_start_time = time();
        set_time_limit(0);
        $this->setStdoutFile($stdout_file);
        //捕获SIGINT信号
        $this->catchSIGINT();
    }

    /**
     * 设置读取类型，默认为读取一行
     * @param $mode int
     * @return void
     */
    public function setReadMode($mode)
    {
        if ($mode == self::READ_FILE_BY_LINE)
            $this->read_mode = self::READ_FILE_BY_LINE;
        else
            $this->read_mode = self::READ_FILE_BY_LENGTH;
    }

    /**
     * 设置每次读取最大量，可以避免内存占用过多，默认为500
     * @param $count int
     * @return void
     */
    public function setMaxRead($count)
    {
        if (!is_numeric($count)) return;
        $this->max_read = $count;
    }

    /**
     * 设置读取长度，默认为2048
     * @param $length int
     * @return void
     */
    public function setReadLength($length)
    {
        if (!is_numeric($length)) return;
        $this->read_length = $length;
    }

    /**
     * 读取失败文件
     */
    public function readFailureFile()
    {
        if (isset($this->_offset[$this->failure_log])) {
            $offset = $this->_offset[$this->failure_log];
        } else {
            $offset = $this->_offset[$this->failure_log] = 0;
        }

        $file_name = $this->failure_log;
        $read_type = $this->read_mode;

        return $this->readFile($file_name, $read_type, $offset);
    }

    /**
     * 取出上次退出数据
     * 传入参数要与记录时保持一致
     * @param $read_type bool
     * @return array
     */
    public function getLastExitData($read_type = false)
    {
        $ret_val = $this->readFile($this->exit_log, self::READ_FILE_BY_LINE, 0);

        if (!$ret_val) return [];

        if (false === $read_type)
            return $ret_val[0];

        return $this->unPackData($ret_val[0]);
    }

    /**
     * 从文件中读取
     * @param $file_name string
     * @param $read_type int
     * @param $offset int
     * @return array
     */
    private function readFile($file_name, $read_type, $offset)
    {
        $ret = [];
        if (!file_exists($file_name)) {
            $file_name = $this->path . $file_name;
            if (!file_exists($file_name) || !is_readable($file_name)) {
                return $ret;
            }
        }

        if (!isset($this->_pointers[$file_name])) {
            $this->_pointers[$file_name] = $fp = fopen($file_name, 'r');
        } else {
            $fp = $this->_pointers[$file_name];
        }
        //fseek($fp, $offset);  wrong
        feof($fp) || fseek($fp, $offset); // true
        $current_length = 0;
        while (!feof($fp) && sizeof($ret) <= $this->max_read) {
            if ($read_type == self::READ_FILE_BY_LINE) {
                //按行读取内容
                $buff = fgets($fp, $this->read_length);
                $current_length += strlen($buff);
                $data = $this->unPackData($buff);
                $data && $ret[] = $data;
            } else {
                //按长度读取内容
                $buff = fread($fp, $this->read_length);
                $content = $this->remain[$file_name] . $buff;
                $content_arr = explode($this->delimiter, $content);
                //当结束符不为切割符时，需要保留最后一段内容
                $delimiter_length = strlen($this->delimiter);
                if (substr($buff, -$delimiter_length) != $this->delimiter)
                    $this->remain[$file_name] = array_pop($content_arr);
                $ret = array_filter(array_map([$this, 'unPackData'], $content_arr));
                $current_length += strlen($buff);
            }

        }
        isset($this->_offset[$file_name]) || $this->_offset[$file_name] = 0;
        $this->_offset[$file_name] += $current_length;
        return $ret;
    }

    /**
     * 记录成功信息
     * @param $data mixed
     * @return mixed
     */
    public function logSuccess($data)
    {
        if (!$data) return false;
        ++$this->success_count;
        ++$this->count;
        $this->last_success = $data;
        return file_put_contents($this->success_log, $this->packData($data) . $this->delimiter, FILE_APPEND);
    }

    /**
     * 记录失败信息
     * @param $data mixed
     * @return mixed
     */
    public function logFailure($data)
    {
        if (!$data) return false;
        ++$this->failure_count;
        ++$this->count;
        $this->last_failure = $data;
        return file_put_contents($this->failure_log, $this->packData($data) . $this->delimiter, FILE_APPEND);
    }

    /**
     * 记录程序退出位置，默认记录int型id,参数可使用整形或本次处理使用数据
     * @param $data mixed
     * @param $current bool
     * @return bool
     */
    public function logExit($data, $current = false)
    {
        $this->last_exit = $data;
        if (false === $current)
            return file_put_contents($this->exit_log, (int)$data);
        else
            return file_put_contents($this->exit_log, $this->packData($data));
    }

    /**
     * 获取总数量
     * @return int
     */
    public function getCount()
    {
        return $this->count;
    }

    /**
     * 获取成功数量
     * @return int
     */
    public function getSuccessCount()
    {
        return $this->success_count;
    }

    /**
     * 获取失败总数量
     * @return int
     */
    public function getFailureCount()
    {
        return $this->failure_count;
    }

    /**
     * 获取路径前缀
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * 针对当前数据类型打包数据
     * @param $data mixed
     * @return mixed
     */
    private function packData($data)
    {
        if ($this->data_type == 'int')
            return (int)$data;

        if ($this->data_type == 'json')
            return str_replace($this->delimiter, '', json_encode($data));

        if ($this->data_type == 'csv')
            return str_replace($this->delimiter, '', implode(',', $data));

        return '';
    }

    /**
     * 针对当前数据类型解包数据
     * @param $data mixed
     * @return mixed
     */
    private function unPackData($data)
    {
        $ret = [];
        if ($this->data_type == 'int')
            return (int)$data;

        if ($this->data_type == 'json') {
            $decode = [];
            $data && $decode = json_decode($data, true);
            $decode && $ret = $decode;
        }

        if ($this->data_type == 'csv'){
            $data && $ret = explode(',', $data);
        }

        return $ret;
    }

    /**
     * 读取数据文件
     * @param $file_name string
     * @param $read_type int
     * @return array
     */
    public function readDataFile($file_name, $read_type = self::READ_FILE_BY_LINE)
    {
        if (!isset($this->_offset[$file_name]))
        {
            $offset = 0;
        } else {
            $offset = $this->_offset[$file_name];
        }

        return $this->readFile($file_name, $read_type, $offset);
    }

    /**
     * 获取路径
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * 设定程序结束时间
     * @param $time int
     */
    public function setEndTime($time = 0)
    {
        $end = 0 === $time ? time() : $time;
        $this->_end_time = $end;
    }

    /**
     * 获取程序结束时间
     * @return int
     */
    public function getEndTime()
    {
        $this->_end_time = $this->_end_time === 0 ? time() : $this->_end_time === 0;
        return $this->_end_time;
    }

    /**
     * 展示程序运行记录
     * @param $process_name string
     */
    public function displayUI($process_name = '')
    {
        if ($this->is_displayed) return;
        $this->is_displayed = true;
        $end_time = $this->_end_time  = time();
        $delay = $this->getDelay($end_time);
        $process_name = $process_name == '' ? $this->prefix . '程序' : $process_name;
        echo "\n\n";
        echo "---------------------------$process_name 运行结果-------------------------------\n\n";

        echo " all     num : [ " . str_pad($this->getCount(), 10) . "]\n";
        echo " success num : [\033[32m " . str_pad($this->getSuccessCount(), 10)  . "\033[\0m] \n";
        echo " failure num : [\033[31m " . str_pad($this->getFailureCount(), 10)  . "\033[\0m] \n";

        echo " 程序开始时间： " . date('Y-m-d H:i:s', $this->_start_time) . "\n";
        echo " 程序结束时间： " . date('Y-m-d H:i:s', $this->_end_time) . "\n";
        echo " 耗时 : " . str_pad($delay['hour'], 2) . ' 时 ' .str_pad($delay['minute'], 2) . '分 ' . str_pad($delay['sec'], 2) . "秒\n";
        echo "\n\n";
    }

    /**
     * 获取运行时长
     * @param $time int
     * @return array
     */
    public function getDelay($time)
    {
        $delay = $time - $this->_start_time;
        $hour = (int)($delay / 60 / 60);
        $minute = (int)(($delay - $hour * 60 * 60) / 60);
        $sec = $delay - $hour * 60 * 60 - $minute * 60;

        $ret = [
            'hour' => $hour,
            'minute' => $minute,
            'sec' => $sec
        ];
        return $ret;
    }

    /**
     * 注册退出函数
     */
    private function register()
    {
        register_shutdown_function(function($log){
            //记录错误信息
            $record = [
                'last_success' => $log->last_success,
                'last_failure' => $log->last_failure,
                'last_exit' => $log->last_exit,
            ];

            $err = error_get_last();
            if ($err['file'] != 'Unknown') {
                echo "\n\n获取到last_error :\n\n";
                print_r($err);
            }

            echo "\n\n程序运行记录如下:\n" . var_export($record, true) . "\n\n";
        }, $this);
        register_shutdown_function([$this, 'displayUI']);
    }

    /**
     * 设置重定向
     * @param $file_name string 重定向文件路径  需要填写绝对路径
     *
     */
    public function setStdoutFile($file_name = '')
    {
       if ('' === $file_name)
           $this->stdoutFile = $this->path . $this->prefix . '_std.log';
       else
           $this->stdoutFile = $file_name;
    }

    /**
     * 程序输出重定向
     */
    public function resetStd()
    {
        if (DIRECTORY_SEPARATOR != "/") {
            return;
        }

        global $STDOUT, $STDERR;
        touch($this->stdoutFile);
        $handle = fopen($this->stdoutFile, "a");
        if ($handle) {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen($this->stdoutFile, "a");
            $STDERR = fopen($this->stdoutFile, "a");
        } else {
            echo 'can not open stdoutFile ' . $this->stdoutFile . "\n";
            exit;
        }
        //注册退出函数，在程序退出时记录详细的信息
        $this->register();
    }

    /**
     * 捕获SIGINT信号
     */
    public function catchSIGINT()
    {
        if (!extension_loaded('pcntl')) {
            return;
        }
        declare(ticks = 1);
        pcntl_signal(SIGINT, function() {
           echo "\n\n捕获到SIGINT信号，程序退出\n\n";
           $this->displayUI();
           exit;
        });
    }
}
