<?php
include 'functions.php';

// 获取请求参数
$jsonData = file_get_contents("php://input");
// 解析JSON数据
$jsonObj = json_decode($jsonData);
// 现在可以使用$jsonObj访问传递的JSON数据中的属性或方法
// 获取token，通过token获取用户名
$token = $jsonObj->token;
if(empty($token)) {
  echo json_encode(array(
    'err' => 1,
    'msg' => 'Token is empty'
  ));
  return;
}
session_id($token);
// 强制禁止浏览器的隐式cookie中的sessionId
$_COOKIE = [ 'PHPSESSID' => '' ];
session_start([ // php7
    'cookie_lifetime' => 2000000000,
    'read_and_close'  => false,
]);
// 获取用户名
$userId = isset($_SESSION['uid']) && is_string($_SESSION['uid']) ? $_SESSION['uid'] : $_SESSION['username'];
if(!isset($userId)) {
  echo json_encode(array(
    'err' => 1,
    'msg' => 'User information not obtained'
  ));
  return;
}
// 获取要进行的操作
$action = $jsonObj->action;

if($action == "getConfig") {
  // 获取homes目录中管理应用的配置的目录
  $hmoesExtAppsFolder = getHomesAppsDir();
  if($hmoesExtAppsFolder == "") {
    // homes目录未开启，提醒用户开启homes目录
    echo json_encode(array(
      'err' => 1,
      'msg' => 'Please enable the home directory in the User Account before use'
    ));
    return;
  }
  // 判断服务状态
  $enable = false;
  // 判断DDNS GO服务是否已经安装
  if(checkServiceExist("ddns-go")) {
    // DDNS GO服务已经安装，判断是否运行
    $enable = checkServiceStatus("ddns-go");
  }
  // 获取共享文件夹列表
  $shareFolders = getAllSharefolder();
  // 获取homes目录中外部应用的配置的目录，即默认的配置目录
  $homesExtConfigFolder = getDefaultConfigDir();
  // 读取配置文件中的配置
  $manageConfigFile = $hmoesExtAppsFolder.'/ddns-go/config.json';
  if(file_exists($manageConfigFile)) {
    $jsonString = file_get_contents($manageConfigFile);
    // 如果想要以数组形式解码JSON，可以传递第二个参数为true
    $manageConfigData = json_decode($jsonString, true);
    $manageConfigData['enable'] = $enable;
    $manageConfigData['shareFolders'] = $shareFolders;
    $manageConfigData['homesExtConfigFolder'] = $homesExtConfigFolder;
    if(empty($manageConfigData['configDir'])) {
      $manageConfigData['configDir'] = $homesExtConfigFolder;
    }
    echo json_encode($manageConfigData);
  } else {
    echo json_encode(array(
      'enable' => $enable,
      'homesExtConfigFolder' => $homesExtConfigFolder,
      'shareFolders' => $shareFolders,
      'configDir' => $homesExtConfigFolder,
      'port' => 9876,
      'updateInterval' => 300,
      'comparisonInterval' => 6,
      'skipVerifyCert' => false,
      'noWeb' => false
    ));
  }
} if($action == "manage") {
  // 保存配置并启动或者停止服务
  // 获取homes目录中管理应用的配置的目录
  $hmoesExtAppsFolder = getHomesAppsDir();
  if($hmoesExtAppsFolder == "") {
    // homes目录未开启，提醒用户开启homes目录
    echo json_encode(array(
      'err' => 1,
      'msg' => 'Please enable the home directory in the User Account before use'
    ));
    return;
  }
  // 获取homes目录中外部应用的配置的目录，即默认的配置目录
  $homesExtConfigFolder = getDefaultConfigDir();
  // 是否启用ddns-go服务
  $enable = false;
  if (property_exists($jsonObj, "enable")) {
    $enable = $jsonObj->enable;
  }
  // ddns-go的配置文件目录
  if (property_exists($jsonObj, 'configDir')) {
    $configDir = $jsonObj->configDir;
    if($configDir == $homesExtConfigFolder) {
      // 如果配置目录为默认目录，则判断默认配置目录是否存在
      if (!is_dir($homesExtConfigFolder)) {
        // 默认配置目录不存在，创建默认配置目录
        exec("sudo mkdir -p $homesExtConfigFolder");
        // 此处不判断是否创建成功，交由后续判断统一处理
      }
    }
  } else {
    // 配置目录未设置
    echo json_encode(array(
      'err' => 2,
      'msg' => 'No configuration directory set'
    ));
    return;
  }

  // 检测配置目录是否存在
  if (is_dir($configDir)) {
    $ddnsGoConfigDir = $configDir."/ddns-go";
    if (!is_dir($ddnsGoConfigDir)) {
      // 文件夹不存在，创建文件夹
      exec("sudo mkdir -p $ddnsGoConfigDir");
      // 此处不判断是否创建成功，交由后续判断统一处理
    }
    if (is_dir($ddnsGoConfigDir)) {
      // 设置www-data对ddnsGo配置文件目录访问权限
      exec("sudo setfacl -d -m u:www-data:rwx $ddnsGoConfigDir && sudo setfacl -m m:rwx $ddnsGoConfigDir && sudo setfacl -R -m u:www-data:rwx $ddnsGoConfigDir");
    } else {
      // ddnsGo配置目录创建失败
      echo json_encode(array(
        'err' => 2,
        'msg' => 'Failed to create Configuration directory'
      ));
      return;
    }
  } else {
    // 配置目录不存在
    echo json_encode(array(
      'err' => 2,
      'msg' => 'Configuration directory is not exist'
    ));
    return;
  }
  
  // ddns-go的端口，默认9876
  $port = 9876;
  if (property_exists($jsonObj, 'port')) {
    $port = $jsonObj->port;
  }
  // ddns-go的更新间隔，默认300秒
  $updateInterval = 300;
  if (property_exists($jsonObj, 'updateInterval')) {
    $updateInterval = $jsonObj->updateInterval;
  }
  // ddns-go的比较间隔，默认间隔6，即ddns-go每检查6次跟ddns服务商比对一次
  $comparisonInterval = 6;
  if (property_exists($jsonObj, 'comparisonInterval')) {
    $comparisonInterval = $jsonObj->comparisonInterval;
  }
  // ddns-go的跳过证书验证，默认false，即不跳过
  $skipVerifyCert = false;
  if (property_exists($jsonObj, 'skipVerifyCert')) {
    $skipVerifyCert = $jsonObj->skipVerifyCert;
  }
  // ddns-go的是否不启动web，默认false，即启动web
  $noWeb = $jsonObj->noWeb?: false;
  if (property_exists($jsonObj, 'noWeb')) {
    $noWeb = $jsonObj->noWeb;
  }
  $manageConfigData = array(
    'configDir' => $configDir,
    'port' => $port,
    'updateInterval' => $updateInterval,
    'comparisonInterval' => $comparisonInterval,
    'skipVerifyCert' => $skipVerifyCert,
    'noWeb' => $noWeb
  );
  // ddns-go的自定义DNS
  if (property_exists($jsonObj, 'dns')) {
    $dns = $jsonObj->dns;
    $manageConfigData['dns'] = $dns;
  }

  // 保存管理程序的配置
  $result = saveManageConfig($hmoesExtAppsFolder.'/ddns-go', $manageConfigData);
  if($result == false) {
    // 配置写入文件失败
    echo json_encode(array(
      'err' => 1,
      'msg' => 'Failed to save configuration'
    ));
    return;
  }

  // ddns-go的程序文件
  $appFile = "/unas/apps/ddns-go/sbin/ddns-go";
  // 修改ddns-go的权限和所有者
  exec("sudo chown www-data:www-data $appFile");
  exec("sudo chmod 755 $appFile");

  // ddns-go的卸载命令
  $unInstallServiceCommand = "sudo /unas/apps/ddns-go/sbin/uninstall.sh";
  if($enable) {
    $skipVerifyCertStr = $skipVerifyCert ? "true" : "false";
    $noWebStr = $noWeb ? "true" : "false";
    // ddns-go的安装命令
    $startServiceCommand = "sudo $appFile -s install -l :$port -f $updateInterval -cacheTimes $comparisonInterval -c $ddnsGoConfigDir/ddns-go-config.yaml";
    if($skipVerifyCert) {
      $startServiceCommand = $startServiceCommand." -skipVerify";
    }
    if($noWeb) {
      $startServiceCommand = $startServiceCommand." -noweb";
    }
    if (isset($dns) && !empty($dns)) {
      $startServiceCommand = $startServiceCommand." -dns $dns";
    }
    // error_log("安装命令为：".$startServiceCommand);

    // 判断DDNS GO服务是否已经安装
    if(checkServiceExist("ddns-go")) {
      // DDNS GO服务已经安装，则执行卸载后再安装
      // error_log("service already exists, uninstalling...");
      $result = exec($unInstallServiceCommand." && ".$startServiceCommand);
      // error_log("服务重新安装，结果为：".$result);
    } else {
      // DDNS GO服务未安装，则执行安装
      $result = exec($startServiceCommand);
      // error_log("服务安装，结果为：".$result);
    }
  } else {
    // 判断DDNS GO服务是否已经安装
    if(checkServiceExist("ddns-go")) {
      // DDNS GO服务已经安装，则执行卸载
      $result = exec($unInstallServiceCommand);
    }
  }
  echo json_encode(array(
    'err' => 0
  ));
} if($action == "checkport") {
  $port = $jsonObj->port;
  if(isset($port)) {
    if (is_numeric($port)) {
      if ($port >= 1 && $port <= 65535 ) {
        if (isPortOccupied($port)) {
          echo json_encode(array(
            'err' => 1,
            'msg' => 'Port has been used'
          ));
          return;
        }
        echo json_encode(array(
          'err' => 0
        ));
        return;
      }
    }
  }
  // 返回错误提示
  echo json_encode(array(
    'err' => 1,
    'msg' => 'Port should between 1 and 65535'
  ));
}
?>