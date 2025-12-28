# Интеграция Whisper AGI в FreePBX

Инструкция по настройке голосового набора внутреннего номера через распознавание речи.

## Схема работы

```
Входящий звонок → IVR → "Скажите номер" → Запись → Whisper API → Перевод на внутренний номер
```

## 1. Установка скрипта

```bash
# Копируем файлы
cp whisper-agi.php /var/lib/asterisk/agi-bin/
cp whisper-config-example.php /var/lib/asterisk/agi-bin/whisper-config.php

# Устанавливаем права
chown asterisk:asterisk /var/lib/asterisk/agi-bin/whisper-agi.php
chown asterisk:asterisk /var/lib/asterisk/agi-bin/whisper-config.php
chmod +x /var/lib/asterisk/agi-bin/whisper-agi.php
```

## 2. Настройка конфигурации

Редактируем `/var/lib/asterisk/agi-bin/whisper-config.php`:

```php
<?php
define('OPENAI_API_KEY', 'sk-ваш-ключ');
define('GPT_PROXY_BASE_URL', 'https://gptproxy.example.com/v1/');
define('WHISPER_API_URL', rtrim(GPT_PROXY_BASE_URL, '/') . '/audio/transcriptions');
define('RECORDINGS_PATH', '/var/spool/asterisk/monitor/');
define('RECORDING_EXT', '.wav');
define('DEBUG', true);
```

## 3. Создание Custom Context в FreePBX

### 3.1 Открыть файл extensions_custom.conf

```bash
nano /etc/asterisk/extensions_custom.conf
```

### 3.2 Добавить контекст для голосового набора

```ini
[speech-to-extension]
; Входная точка - воспроизводим приглашение и записываем голос
exten => s,1,Answer()
exten => s,n,Wait(0.5)

; Воспроизводим приглашение (можно заменить на свой файл)
; "Скажите внутренний номер сотрудника"
exten => s,n,Playback(custom/say-extension-number)

; Записываем голос абонента
; Параметры: файл, тишина для остановки (сек), макс длительность (сек)
exten => s,n,Record(/var/spool/asterisk/monitor/${UNIQUEID}.wav,3,10,q)

; Отправляем на распознавание
exten => s,n,AGI(whisper-agi.php)

; Логируем результат
exten => s,n,NoOp(Whisper Status: ${WHISPER_STATUS})
exten => s,n,NoOp(Transcribed Text: ${TRANSCRIBED_TEXT})
exten => s,n,NoOp(Transcribed Digits: ${TRANSCRIBED_DIGITS})

; Проверяем успешность распознавания
exten => s,n,GotoIf($["${WHISPER_STATUS}" != "SUCCESS"]?error)
exten => s,n,GotoIf($["${TRANSCRIBED_DIGITS}" = ""]?error)

; Проверяем что номер существует (от 100 до 999 - типичные внутренние номера)
exten => s,n,GotoIf($["${TRANSCRIBED_DIGITS}" < "100"]?invalid)
exten => s,n,GotoIf($["${TRANSCRIBED_DIGITS}" > "9999"]?invalid)

; Переводим на внутренний номер через from-internal
exten => s,n,NoOp(Transferring to extension ${TRANSCRIBED_DIGITS})
exten => s,n,Goto(from-internal,${TRANSCRIBED_DIGITS},1)

; Обработка ошибок
exten => s,n(error),Playback(an-error-has-occurred)
exten => s,n,Goto(s,1)

exten => s,n(invalid),Playback(invalid)
exten => s,n,Goto(s,1)

; Обработка таймаута и hangup
exten => h,1,NoOp(Caller hung up)


[speech-to-extension-with-retry]
; Версия с повторными попытками (максимум 3)
exten => s,1,Answer()
exten => s,n,Set(ATTEMPTS=0)
exten => s,n(start),Set(ATTEMPTS=$[${ATTEMPTS} + 1])
exten => s,n,GotoIf($[${ATTEMPTS} > 3]?maxattempts)

exten => s,n,Wait(0.5)
exten => s,n,Playback(custom/say-extension-number)
exten => s,n,Record(/var/spool/asterisk/monitor/${UNIQUEID}-${ATTEMPTS}.wav,3,10,q)

; Меняем UNIQUEID для AGI чтобы найти правильный файл
exten => s,n,Set(ORIG_UNIQUEID=${UNIQUEID})
exten => s,n,Set(__UNIQUEID=${UNIQUEID}-${ATTEMPTS})
exten => s,n,AGI(whisper-agi.php)
exten => s,n,Set(__UNIQUEID=${ORIG_UNIQUEID})

exten => s,n,NoOp(Attempt ${ATTEMPTS}: Status=${WHISPER_STATUS} Digits=${TRANSCRIBED_DIGITS})

exten => s,n,GotoIf($["${WHISPER_STATUS}" != "SUCCESS"]?retry)
exten => s,n,GotoIf($["${TRANSCRIBED_DIGITS}" = ""]?retry)
exten => s,n,GotoIf($["${TRANSCRIBED_DIGITS}" < "100"]?retry)
exten => s,n,GotoIf($["${TRANSCRIBED_DIGITS}" > "9999"]?retry)

; Успех - переводим
exten => s,n,Playback(pls-hold-while-try)
exten => s,n,Goto(from-internal,${TRANSCRIBED_DIGITS},1)

exten => s,n(retry),Playback(please-try-again)
exten => s,n,Goto(start)

exten => s,n(maxattempts),Playback(goodbye)
exten => s,n,Hangup()

exten => h,1,NoOp(Caller hung up)
```

### 3.3 Применить конфигурацию

```bash
asterisk -rx "dialplan reload"
```

## 4. Создание Custom Destination в FreePBX

### 4.1 Через веб-интерфейс

1. Открыть **Admin → Custom Destinations**
2. Нажать **Add Destination**
3. Заполнить:
   - **Target**: `speech-to-extension,s,1` (или `speech-to-extension-with-retry,s,1`)
   - **Description**: `Голосовой набор внутреннего номера`
   - **Notes**: `Распознавание речи через Whisper API`
4. Нажать **Submit** → **Apply Config**

## 5. Подключение к IVR

### 5.1 Через веб-интерфейс

1. Открыть **Applications → IVR**
2. Выбрать нужный IVR или создать новый
3. В разделе **IVR Entries** добавить:
   - **Digits**: `0` (или другая цифра для голосового набора)
   - **Destination**: `Custom Destinations → Голосовой набор внутреннего номера`
4. Или установить как **Invalid Destination** / **Timeout Destination**
5. Нажать **Submit** → **Apply Config**

### 5.2 Альтернатива: голосовой набор по умолчанию

Если хотите, чтобы голосовой набор был основным методом:

1. В IVR установить **Timeout** на 2-3 секунды
2. **Timeout Destination** → `Custom Destinations → Голосовой набор`

## 6. Создание голосового приглашения

### 6.1 Записать через System Recordings

1. Открыть **Admin → System Recordings**
2. Нажать **Add Recording**
3. Набрать номер записи с телефона и записать: *"Назовите внутренний номер сотрудника"*
4. Сохранить как `say-extension-number`

### 6.2 Или загрузить файл

```bash
# Скопировать wav файл
cp say-extension-number.wav /var/lib/asterisk/sounds/custom/
chown asterisk:asterisk /var/lib/asterisk/sounds/custom/say-extension-number.wav
```

## 7. Проверка работы

### 7.1 Тестовый звонок

1. Позвоните на номер IVR
2. Нажмите цифру для голосового набора (или дождитесь таймаута)
3. Произнесите номер: "сто один" или "один ноль один"
4. Должен произойти перевод на ext 101

### 7.2 Просмотр логов

```bash
# Asterisk CLI
asterisk -rvvv

# Логи AGI скрипта
tail -f /var/log/asterisk/full | grep Whisper
```

### 7.3 Проверка записей

```bash
ls -la /var/spool/asterisk/monitor/
```

## 8. Troubleshooting

### Ошибка "Recording file not found"

```bash
# Проверить права на папку записей
ls -la /var/spool/asterisk/monitor/
chown -R asterisk:asterisk /var/spool/asterisk/monitor/
chmod 775 /var/spool/asterisk/monitor/
```

### Ошибка "Transcription failed"

```bash
# Проверить доступ к API
curl -X POST "https://gptproxy.example.com/v1/audio/transcriptions" \
  -H "Authorization: Bearer sk-your-key" \
  -F "file=@test.wav" \
  -F "model=whisper-1"
```

### AGI скрипт не выполняется

```bash
# Проверить права
ls -la /var/lib/asterisk/agi-bin/whisper-agi.php

# Проверить синтаксис PHP
php -l /var/lib/asterisk/agi-bin/whisper-agi.php

# Проверить наличие модулей PHP
php -m | grep -E "curl|json|mbstring"
```

## 9. Дополнительные настройки

### Ограничение по времени записи

В `extensions_custom.conf` измените параметры Record:
```ini
; Record(файл, тишина_сек, макс_длительность_сек, опции)
exten => s,n,Record(/var/spool/asterisk/monitor/${UNIQUEID}.wav,2,5,q)
```

### Очистка старых записей

Добавьте в cron:
```bash
# Удалять записи старше 1 часа
0 * * * * find /var/spool/asterisk/monitor/ -name "*.wav" -mmin +60 -delete
```

