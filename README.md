# Asterisk Whisper AGI Script

PHP 5.6-совместимый AGI скрипт для распознавания речи через OpenAI Whisper API.

## Установка

```bash
# Копирование скриптов
cp whisper-agi.php /var/lib/asterisk/agi-bin/
cp whisper-config-example.php /var/lib/asterisk/agi-bin/whisper-config.php
chmod +x /var/lib/asterisk/agi-bin/whisper-agi.php

# Редактирование конфигурации
nano /var/lib/asterisk/agi-bin/whisper-config.php
```

## Конфигурация

Скопируйте `whisper-config-example.php` в `whisper-config.php` и настройте параметры:

```bash
cp whisper-config-example.php whisper-config.php
```

Или используйте переменные окружения (приоритет у конфиг-файла):

```bash
export OPENAI_API_KEY="sk-your-key"
export GPT_PROXY_URL="https://gptproxy.example.com/v1/"
```

## Пример Dialplan

```ini
[ivr-speech]
exten => s,1,Answer()
exten => s,n,Wait(1)
exten => s,n,Playback(beep)
; Записываем голос абонента (5 секунд тишины = стоп)
exten => s,n,Record(/var/spool/asterisk/monitor/${UNIQUEID}.wav,5,30)
; Отправляем на распознавание
exten => s,n,AGI(whisper-agi.php)
; Результат в ${TRANSCRIBED_DIGITS}
exten => s,n,NoOp(Статус: ${WHISPER_STATUS})
exten => s,n,NoOp(Текст: ${TRANSCRIBED_TEXT})
exten => s,n,NoOp(Цифры: ${TRANSCRIBED_DIGITS})
; Используем распознанные цифры
exten => s,n,GotoIf($["${TRANSCRIBED_DIGITS}" != ""]?has_digits:no_digits)
exten => s,n(has_digits),Goto(internal,${TRANSCRIBED_DIGITS},1)
exten => s,n(no_digits),Playback(invalid)
exten => s,n,Hangup()
```

## Переменные AGI

Скрипт устанавливает следующие переменные:

| Переменная | Описание |
|------------|----------|
| `TRANSCRIBED_DIGITS` | Только цифры из распознанного текста |
| `TRANSCRIBED_TEXT` | Полный распознанный текст |
| `WHISPER_STATUS` | Статус: SUCCESS, ERROR_NO_UNIQUEID, ERROR_FILE_NOT_FOUND, ERROR_TRANSCRIPTION_FAILED |

## Требования

- PHP 5.6+ с модулями: curl, json, mbstring
- Asterisk с поддержкой AGI
- Доступ к OpenAI API (через прокси)

