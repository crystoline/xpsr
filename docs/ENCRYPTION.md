# Encryption

`Crystoline\Xpsr\Encryption\Encrypter` encrypts values with the application key, for data that must be stored but kept unreadable without the key, such as a customer's API credentials or a bank account number. It uses Laravel's format: data that a Laravel application encrypted with the same key decrypts in XPSR, and the other way round.

It needs PHP's `openssl` extension and no package.

## The key

Make a key and put it in `.env`:

```sh
php xpsr key:generate --write
```

That sets `APP_KEY` to `base64:` followed by 32 random bytes in base64. `key:generate` without `--write` prints a key without saving it. With `--write`, it refuses to replace a key that is already set; `--force` replaces it, and data encrypted with the old key can then only be read with the old key in `APP_PREVIOUS_KEYS`.

`config/app.php` reads the key and its settings:

```php
'key' => env('APP_KEY'),
'previous_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_PREVIOUS_KEYS', ''))))),
'cipher' => 'AES-256-CBC',
```

| Setting | Meaning |
|---|---|
| `key` | `base64:` and the key in base64, as `key:generate` and Laravel write it, or the raw key. 32 bytes for AES-256, 16 for AES-128. |
| `previous_keys` | Older keys, comma-separated in `APP_PREVIOUS_KEYS`. They decrypt but never encrypt. |
| `cipher` | `AES-256-CBC` (the default, as in Laravel), `AES-128-CBC`, `AES-256-GCM`, or `AES-128-GCM`. Data encrypted with one cipher does not decrypt with another. |

An application without a key starts normally. Only code that encrypts or decrypts fails, with a message that says to run `key:generate --write`.

## Encrypting and decrypting

Inject the encrypter:

```php
use Crystoline\Xpsr\Encryption\Encrypter;

public function __construct(private readonly Encrypter $encrypter) {}

$stored = $this->encrypter->encryptString($apiSecret);
$apiSecret = $this->encrypter->decryptString($stored);
```

| Method | Use |
|---|---|
| `encryptString($text)`, `decryptString($payload)` | Text. Laravel's `encryptString()` and `decryptString()` read the same payloads. |
| `encrypt($value)`, `decrypt($payload)` | Strings, numbers, booleans, null, and arrays of these. They are serialized as Laravel's `encrypt()` serializes them, so each side reads the other's. |

Every payload is new: encrypting the same text twice gives different results. A payload is base64 text of about 1.8 times the value's length plus about 200 characters (1,000 bytes become 1,960 characters with AES-256-CBC), so store it in a `TEXT` column.

Decrypting fails with `Crystoline\Xpsr\Encryption\DecryptException` when the payload was changed, was encrypted with a key that is neither `app.key` nor in `app.previous_keys`, or is not a payload at all. CBC payloads carry an HMAC-SHA256 of the IV and ciphertext, and GCM payloads their tag, so a changed payload never decrypts to different data.

### Objects are not restored

`decrypt()` refuses data that holds a serialized PHP object, and `encrypt()` refuses objects. Laravel's `decrypt()` restores objects. Anyone who learns its key can then craft a payload that makes the application's own classes run code while the object is restored. That has happened to Laravel applications whose `APP_KEY` leaked. XPSR reads only plain values, so a leaked key exposes the encrypted data but not the server.

To encrypt an object, encode it first, for example `encryptString(json_encode($value))`. To read a Laravel payload that holds an object, `decryptString()` returns the serialized text, and the code can decide what to do with it.

## Eloquent

With [`crystoline/xpsr-orm-eloquent`](../packages/orm-eloquent/README.md), Eloquent's encrypted casts use this encrypter: `encrypted`, `encrypted:array`, `encrypted:collection`, `encrypted:object`, `AsEncryptedArrayObject`, and `AsEncryptedCollection`. Columns that a Laravel application encrypted read as they are.

## Changing the key

1. Add the current key to the front of `APP_PREVIOUS_KEYS`.
2. Set `APP_KEY` to a new key from `php xpsr key:generate`.
3. Deploy to every server. Old data still decrypts with the previous key; new data uses the new one.
4. Re-encrypt old data when convenient: decrypt each value and encrypt it again. With previous keys set, Eloquent saves an encrypted attribute whenever it is assigned, even its own value: `$model->token = $model->token; $model->save();`.
5. Once no data uses the old key, remove it from `APP_PREVIOUS_KEYS`.

Every decryption that falls back to a previous key costs one more HMAC check, or one more decryption attempt with GCM, so keep the list short.

## Coming from Laravel

Copy `APP_KEY`, and `APP_PREVIOUS_KEYS` if it is set, from the Laravel application's `.env`, and keep `app.cipher` the same as Laravel's `config/app.php` (`AES-256-CBC` unless it was changed). Encrypted columns, and values from Laravel's `encryptString()` and `encrypt()`, then read in XPSR. Values from `encrypt()` that hold objects do not; see [Objects are not restored](#objects-are-not-restored).

Laravel also encrypts its cookies with `APP_KEY`. XPSR does not encrypt cookies, and does not read Laravel's session or cookie values.
