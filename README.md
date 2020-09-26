# yii2-datadog

## Installation

```
"ingelby/yii2-datadog": "^0.*"
```

## Usage

### Logging

Add to log component

```
[
    'class'         => DataDogTarget::class,
    'levels'        => ['info', 'warning', 'error'],
    'dataDogApiKey' => 'DD_API_KEY',
    'hostname'      => gethostname(),
    'environment'   => 'ENV_NAME',
    'service'       => 'SERVICE_NAME',
]
```
