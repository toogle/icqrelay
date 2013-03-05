<?php
/*
 * Copyright (C) 2013 toogle <tooogle@mail.ru>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public
 * License v2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public
 * License along with this program; if not, write to the
 * Free Software Foundation, Inc., 59 Temple Place - Suite 330,
 * Boston, MA 021110-1307, USA.
 */

//error_reporting(0);  // turn off error reporting
date_default_timezone_set('Europe/Moscow');

require_once('WebIcqPro.class.php');

define('VERSION',     '0.3b');
define('CONFIG_FILE', 'config.ini');


function startswith($haystack, $needle) {
    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

class IcqRelay {
    protected $icq = NULL;
    protected $myUin = NULL;
    protected $associations = array();
    protected $log = NULL;
    protected $adminPassword = NULL;
    protected $adminTimeout = NULL;
    protected $admins = array();

    function __construct($uin, $password, $status = 'Ready for your service!') {
        $this->icq = new WebIcqPro();

        if (!$this->icq->connect($uin, $password)) {
            throw new Exception($this->icq->error);
        }

        $this->icq->setStatus('STATUS_ONLINE', 'STATUS_DCAUTH', $status);

        $this->myUin = $uin;
    }

    function __destruct() {
        if (!is_null($this->icq) && $this->icq->isConnected()) {
            $this->icq->disconnect();
        }

        if (!is_null($this->log)) {
            fclose($this->log);
        }
    }

    public function enableLogging($logfile) {
        $this->log = fopen($logfile, "a+");
    }

    public function enableAdmin($password, $timeout = 60) {
        $this->adminPassword = $password;
        $this->adminTimeout = $timeout;
    }

    public function run() {
        $this->writeLog("ICQ Relay v" . VERSION . " started");

        while ($this->icq->isConnected()) {
            $m = $this->icq->readMessage();
            if (!is_array($m) || !isset($m['type']) || !isset($m['from'])) {
                continue;
            }

            $from = $m['from'];

            switch ($m['type']) {
            case 'message':
                break;
            case 'authrequest':
                $this->icq->setAuthorization($from, true, 'Have fun!');
                $this->writeLog("ICQ authorization request: granted", $from);
                continue;
            case 'authresponse':
                $this->writeLog("ICQ authorization response: {$m['granted']}", $from);
                continue;
            case 'error':
                $this->writeLog("ICQ error: {$m['error']} ({$m['code']})");
                continue;
            default:
                continue;
            }

            switch ($m['encoding']['numset']) {
            case 'UNICODE':
                $msg = mb_convert_encoding($m['message'], 'CP1251', 'UTF-16');
                break;
            case 'UTF-8':
                $msg = mb_convert_encoding($m['message'], 'CP1251', 'UTF-8');
                break;
            default:
                $msg = $m['message'];
            }

            $orig = $this->getAssociateOrigin($from);
            if ($orig) {
                $this->icq->sendMessage($orig, $msg);

                continue;
            }

            if (startswith($msg, '!help')) {
                $this->cmdHelp($from, $msg);
            } elseif (startswith($msg, '!status')) {
                $this->cmdStatus($from, $msg);
            } elseif (startswith($msg, '!start')) {
                $this->cmdStart($from, $msg);
            } elseif (startswith($msg, '!end')) {
                $this->cmdEnd($from, $msg);
            } elseif (startswith($msg, '!admin')) {
                $this->cmdAdmin($from, $msg);
            } elseif (startswith($msg, '!logout')) {
                $this->cmdLogout($from, $msg);
            } elseif (startswith($msg, '!clear')) {
                $this->cmdClear($from, $msg);
            } elseif (startswith($msg, '!dump')) {
                $this->cmdDump($from, $msg);
            } elseif (startswith($msg, '!showlog')) {
                $this->cmdShowLog($from, $msg);
            } else {
                $assoc = $this->getAssociate($from);
                if ($assoc) {
                    $this->icq->sendMessage($assoc, $msg);
                } else {
                    $this->icq->sendMessage($from, "Sorry, I don't understand you... Read \"!help\".");
                }
            }
        }
    }

    protected function writeLog($data, $ident = 'system') {
        if (!is_null($this->log)) {
            fwrite($this->log, date('Y-m-d H:i:s') . " [$ident] $data\n");
        }
    }

    protected function getAssociate($uin) {
        return $this->associations[$uin];
    }

    protected function setAssociate($uin, $assoc) {
        $this->associations[$uin] = $assoc;
    }

    protected function dropAssociate($uin) {
        $old_assoc = $this->associations[$uin];

        unset($this->associations[$uin]);

        return $old_assoc;
    }

    protected function getAssociateOrigin($assoc) {
        return array_search($assoc, $this->associations);
    }

    protected function adminAuthenticate($uin, $password) {
        if (!is_null($this->adminPassword) && $this->adminPassword === $password) {
            $this->admins[$uin] = time() + $this->adminTimeout;

            return true;
        }

        return false;
    }

    protected function adminIsAuthenticated($uin) {
        if (isset($this->admins[$uin])) {
            if ($this->admins[$uin] > time()) {
                $this->admins[$uin] = time() + $this->adminTimeout;  // reset timeout

                return true;
            } else {
                unset($this->admins[$uin]);

                $this->writeLog("administrator session expired", $uin);
            }
        }

        return false;
    }

    protected function adminLogout($uin) {
        unset($this->admins[$uin]);
    }

    protected function cmdHelp($uin, $msg) {
        $help  = "Hello, $uin! I'm ICQ Relay v" . VERSION . " by toogle <tooogle@mail.ru>\r\n\r\n";
        $help .= "Available commands:\r\n";
        $help .= "!help         this help\r\n";
        $help .= "!status       get current status\r\n";
        $help .= "!start <uin>  start new chat with <uin>\r\n";
        $help .= "!end          end current chat\r\n";
        if ($this->adminIsAuthenticated($uin)) {
            $help .= "!clear        drop all associations\r\n";
            $help .= "!dump         show currently established associations\r\n";
            $help .= "!showlog      show logfile contents\r\n";
            $help .= "!logout       exit from administrator mode\r\n";
        } else {
            $help .= "!admin <password>  go to administrator mode\r\n";
        }

        $this->icq->sendMessage($uin, $help);
    }

    protected function cmdStatus($uin, $msg) {
        $assoc = $this->getAssociate($uin);
        if ($assoc) {
            $this->icq->sendMessage($uin, "You're currently in chat with $assoc.");
        } else {
            $this->icq->sendMessage($uin, "You're currently not in chat with anybody.");
        }
    }

    protected function cmdStart($uin, $msg) {
        $assoc = $this->getAssociate($uin);
        if ($assoc) {
            $this->icq->sendMessage($uin, "You're already in chat with $assoc. You should \"!end\" it first.");
        } else {
            $parts = explode(' ', $msg, 2);
            $new_assoc = intval(trim($parts[1]));
            if ($new_assoc == 0) {
                $this->icq->sendMessage($uin, "Invalid uin, please retry.");
            } elseif ($new_assoc == $uin) {
                $this->icq->sendMessage($uin, "Do you feel lonely?");
            } elseif ($new_assoc == $this->myUin) {
                $this->icq->sendMessage($uin, "This is a bad idea!");
            } else {
                $this->setAssociate($uin, $new_assoc);
                $this->writeLog("association with $new_assoc created", $uin);

                $this->icq->sendMessage($uin, "Chat with $new_assoc started. Go on!");
            }
        }
    }

    protected function cmdEnd($uin, $msg) {
        $old_assoc = $this->dropAssociate($uin);
        if ($old_assoc) {
            $this->writeLog("association with $old_assoc dropped", $uin);

            $this->icq->sendMessage($uin, "Chat with $old_assoc ended.");
        } else {
            $this->icq->sendMessage($uin, "You're currently not in chat with anybody.");
        }
    }

    protected function cmdAdmin($uin, $msg) {
        $parts = explode(' ', $msg, 2);
        $password = trim($parts[1]);
        if ($this->adminAuthenticate($uin, $password)) {
            $this->writeLog("authenticated as administrator", $uin);

            $this->icq->sendMessage($uin, "You successfully logged in as admin.");
        } else {
            $this->writeLog("authentication failed with \"$password\"", $uin);

            $this->icq->sendMessage($uin, "Access denied.");
        }
    }

    protected function cmdLogout($uin, $msg) {
        if ($this->adminIsAuthenticated($uin)) {
            $this->adminLogout($uin);
            $this->writeLog("logged out", $uin);

            $this->icq->sendMessage($uin, "You successfully logged out.");
        } else {
            $this->icq->sendMessage($uin, "Access denied.");
        }
    }

    protected function cmdClear($uin, $msg) {
        if ($this->adminIsAuthenticated($uin)) {
            $this->associations = array();
            $this->writeLog("associations cleared", $uin);

            $this->icq->sendMessage($uin, "Associations successfully cleared.");
        } else {
            $this->icq->sendMessage($uin, "Access denied.");
        }
    }

    protected function cmdDump($uin, $msg) {
        if ($this->adminIsAuthenticated($uin)) {
            $dump = "Currently established associations:";
            foreach ($this->associations as $f => $t) {
                $dump .= "\r\n$f <=> $t";
            }

            $this->icq->sendMessage($uin, $dump);
        } else {
            $this->icq->sendMessage($uin, "Access denied.");
        }
    }

    protected function cmdShowLog($uin, $msg) {
        if ($this->adminIsAuthenticated($uin)) {
            if (!is_null($this->log)) {
                $log = '';

                rewind($this->log);
                while (!feof($this->log)) {
                    $log .= fgets($this->log);
                }

                $this->icq->sendMessage($uin, $log);
            } else {
                $this->icq->sendMessage($uin, "Logging is currently turned off.");
            }
        } else {
            $this->icq->sendMessage($uin, "Access denied.");
        }
    }
}


$conf = parse_ini_file(CONFIG_FILE, true);
if (!$conf) {
    exit("Unable to read " . CONFIG_FILE);
}

try {
    $icqRelay = new IcqRelay($conf['uin'], $conf['password']);
} catch (Exception $e) {
    exit($e->getMessage() . "\n");
}
if ($conf['logging']['enable']) {
    $icqRelay->enableLogging($conf['logging']['file']);
}
if ($conf['admin']['enable']) {
    $icqRelay->enableAdmin($conf['admin']['password'], $conf['admin']['timeout']);
}
$icqRelay->run();
?>
