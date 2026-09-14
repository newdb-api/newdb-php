# NewDB PHP SDK

Официальная PHP-библиотека для интеграции с [NewDB REST API](https://newdb.net) — проверкой физических лиц, юридических лиц, паспортов, ФНС, ФССП, банкротств, залогов и арбитражных дел по реестрам РФ (ФНС, ФССП, МВД, Федресурс, КАД Арбитр, ЕГРЮЛ, ЕГРИП, Нотариат).

[![Latest Version on Packagist](https://img.shields.io/packagist/v/newdb/sdk.svg)](https://packagist.org/packages/newdb/sdk)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net)

---

## Установка

Установите пакет через Composer:

```bash
composer require newdb/sdk
```

---

## Быстрый старт

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use NewDB\Client;

$client = new Client('your_api_token');

// 1. Проверка баланса
$balance = $client->getBalance();
echo "Баланс: {$balance['balance']} запросов\n";

// 2. Проверка действительности паспорта РФ (МВД)
$passport = $client->checkPassportMvd('4510', '123456', 'Иван', 'Иванов');
print_r($passport);

// 3. Комплексная проверка организации по ИНН с авто-ожиданием результата
$task = $client->complexCompanyCheck('7707083893');
$completedTask = $client->waitForResult($task['requestId'], 60);
print_r($completedTask['results']);
```

---

### Тестовый режим (Sandbox / Test Mode)

Для тестирования и локальной отладки интеграции без списания баланса используйте параметр `testMode: true`:

```php
use NewDB\Client;

// Активация тестового контура https://api.newdb.net/test/v2
$client = new Client(testMode: true);

// Либо через переменную окружения:
// putenv('NEWDB_TEST_MODE=1');
// $client = new Client();

$res = $client->checkPassportMvd('4510', '123456', 'Иван', 'Иванов');
print_r($res);
```

---

## Поддерживаемые методы

* `checkPassportMvd($seria, $number, $firstname, $lastname)` — проверка действительности паспорта (МВД)
* `checkPassportFns($seria, $number, $firstname, $lastname, $dob)` — получение ИНН и валидация паспорта (ФНС)
* `complexPassportCheck($seria, $number, $firstname, $lastname)` — комплексная проверка физлица
* `checkFssp($firstname, $lastname, $dob, $regioncode = '100')` — долги и исполнительные производства ФССП
* `checkEgrul($inn)` — сведения ЕГРЮЛ / Прозрачный бизнес
* `checkFnsBlock($inn, $bik = null)` — блокировки банковских счетов ФНС
* `complexCompanyCheck($inn)` — комплексная проверка компании

### HTML/PDF-отчеты

```php
$pdf = $client->generateReport($requestId, 'pdf');
$foreignHtml = $client->generateAggregatedReport($requestIds, 'complex_foreign', 'html');
```

Методы возвращают строку с бинарным содержимым готового файла.
* `monitorKadCase($caseNumber)` — процессуальный мониторинг конкретного дела КАД

---

## Документация

* Документация API: [https://newdb.net/docs](https://newdb.net/docs)
* Запрос токена: [access@newdb.net](mailto:access@newdb.net)
* Лицензия: MIT
