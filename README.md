# Collect kuiper component configuration

```json
{
  "extra": {
    "kuiper": {
      "config-file": "config/container.php",
      "component-scan": [],
      "configuration": [],
      "whitelist": [],
      "blacklist": []
    }
  }
}
```

root package config:

| name           | type   | description                                                                |
|----------------|--------|----------------------------------------------------------------------------|
| config-file    | string | the output file name                                                       |
| whitelist      | array  | the package name to collect without ask. Matches using `fnmatch`.          |
| blacklist      | array  | the package name to ignore                                                 |
| component-scan | array  | namespace to scan. Default add all psr-4 namespace. set to false to ignore |
| configuration  | array  | class as configuration                                                     |
