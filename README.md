# hh-symbol

Symbol map builder for use with Hack.

## Usage

The output symbol map is designed to be used with [`\HH\autoload_set_paths`](https://github.com/facebook/hhvm/blob/master/hphp/runtime/ext/hh/ext_hh.php#L29).

```php
<?hh
$root = __DIR__;
\HH\autoload_set_paths(SymbolMapBuilder::build($root), $root);
```
