<?php 

define('VENDOR_DIR', is_dir(__DIR__ . '/../vendor/doctrine-common') ? __DIR__ . '/../vendor' : __DIR__ . '/../lib/vendor');

require_once VENDOR_DIR . '/doctrine-common/lib/Doctrine/Common/ClassLoader.php';

$loader = new \Doctrine\Common\ClassLoader('Doctrine\Common', VENDOR_DIR . '/doctrine-common/lib');
$loader->register();

$loader = new \Doctrine\Common\ClassLoader('Doctrine', __DIR__ . '/../lib');
$loader->register();

$loader = new \Doctrine\Common\ClassLoader('Symfony', VENDOR_DIR);
$loader->register();

$loader = new \Doctrine\Common\ClassLoader('Documents', __DIR__);
$loader->register();

$paths = __DIR__ . '/Documents';

$config = new \Doctrine\OXM\Configuration();
$config->setProxyDir(\sys_get_temp_dir());
$config->setProxyNamespace('Doctrine\Sandbox\Proxies');
$metaDriver = $config->newDefaultAnnotationDriver(array($paths));
$config->setMetadataDriverImpl($metaDriver);
\Doctrine\OXM\Mapping\Driver\AnnotationDriver::registerAnnotationClasses();

$config->setMetadataCacheImpl(new \Doctrine\Common\Cache\ArrayCache);

$storage = new \Doctrine\OXM\Storage\FileSystemStorage(__DIR__ . '/Workspace');

$dm = new \Doctrine\OXM\XmlEntityManager($storage, $config);

ob_start(function($output) {
    if (PHP_SAPI != "cli") {
        return nl2br($output);
    } else {
        return $output;
    }
});
