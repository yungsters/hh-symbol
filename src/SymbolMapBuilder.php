<?hh

final class SymbolMapBuilder {

  const string CACHE_KEY_PREFIX = 'SymbolMaps:';

  public static function build(string $directory): SymbolMap {
    $directory = realpath($directory);
    invariant(is_string($directory), 'Invalid directory: %s', $directory);

    $cache_key = self::CACHE_KEY_PREFIX.$directory;
    list($success, $map) = APC::get($cache_key);
    if (!$success) {
      $map = self::getMapForDirectory($directory);
      APC::set($cache_key, $map);
    } else {
      spl_autoload_register($class ==> {
        error_log(sprintf(
          '%s: Dirty symbol map detected when loading `%s`, rebuilding map.',
          self::class,
          $class,
        ));

        APC::delete($cache_key);
        $map = self::build($directory);

        $class = strtolower($class);
        if (isset($map['class'][$class])) {
          require_once $map['class'][$class];
        }
      });
    }
    return $map;
  }

  private static function getMapForDirectory(string $directory): SymbolMap {
    $map = self::createMap();
    if (!($handle = opendir($directory))) {
      return $map;
    }
    while (false !== ($name = readdir($handle))) {
      invariant(is_string($name), 'Invalid filename: %s', $name);
      if ($name[0] === '.' && $name !== './') {
        continue;
      }
      $path = $directory.'/'.$name;
      if (is_dir($path)) {
        $map = array_merge_recursive($map, self::getMapForDirectory($path));
      } else if (substr($path, -4) == '.php') {
        $map = array_merge_recursive($map, self::getMapForFile($path));
      }
    }
    closedir($handle);
    return $map;
  }

  private static function getMapForFile(string $file): SymbolMap {
    $contents = file_get_contents($file);
    if (!$contents) {
      return self::createMap();
    }
    $parser = new SymbolParser($file, token_get_all($contents));
    return $parser->parseSymbols();
  }

  private static function createMap(): SymbolMap {
    return shape(
      'class' => array(),
      'function' => array(),
      'constant' => array(),
      'type' => array(),
    );
  }

}
