<?php

namespace Nitro\Fusion;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A file the browser uploaded for a component, kept in Fusion's temporary folder until the
 * component stores it: public ?Upload $photo = null, bound with fusion:model="photo" on a file
 * input (Livewire's TemporaryUploadedFile). It is an UploadedFile, so validation rules (image,
 * mimes, max) and store() / storeAs() work as they do for any upload.
 *
 * The browser only ever holds a signed reference to it ({"$u": {...}}): the name of the file in
 * the temporary folder, what the visitor called it, its type and size, signed with the
 * application key, so a reference cannot be made up or pointed at another file.
 */
final class Upload extends UploadedFile
{
    /** Types a preview (temporaryUrl()) may be served as: raster images only (an SVG or HTML page could run script). */
    public const PREVIEWABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'];

    /** How long an upload is kept for its component before it is removed. */
    public const LIFETIME = 86400;

    private function __construct(
        private readonly string $file,
        string $originalName,
        private readonly string $mime,
        private readonly int $bytes,
        /** When it arrived: its reference (and preview URL) stays the same for as long as it is kept. */
        private readonly int $at,
    ) {
        parent::__construct(self::directory().DIRECTORY_SEPARATOR.$file, $originalName, $mime, null, true);
    }

    /** A new upload: the received file moved into the temporary folder under a random name. */
    public static function receive(UploadedFile $received): self
    {
        $extension = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $received->getClientOriginalExtension()) ?? '');
        $file = Str::random(40).($extension === '' ? '' : '.'.substr($extension, 0, 10));
        $directory = self::directory();

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $received->move($directory, $file);
        $path = $directory.DIRECTORY_SEPARATOR.$file;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $name = mb_substr(basename(str_replace('\\', '/', $received->getClientOriginalName())), 0, 255) ?: $file;

        return new self($file, $name, $mime, (int) filesize($path), time());
    }

    /**
     * The upload a signed reference names; anything else (changed, made up, expired, removed)
     * is refused.
     *
     * @throws InvalidArgumentException
     */
    public static function fromWire(mixed $wire): self
    {
        $token = is_array($wire) ? ($wire['t'] ?? null) : null;

        if (! is_string($token) || substr_count($token, '.') !== 1) {
            throw new InvalidArgumentException('Malformed upload.');
        }

        [$payload, $signature] = explode('.', $token);

        if (! hash_equals(self::sign($payload), $signature)) {
            throw new InvalidArgumentException('This upload was not made here.');
        }

        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        if (! is_array($data) || ! is_string($data['f'] ?? null) || ! is_string($data['n'] ?? null) || ! is_string($data['m'] ?? null)
            || ! is_int($data['s'] ?? null) || ! is_int($data['at'] ?? null) || preg_match('/^[A-Za-z0-9]{40}(\.[a-z0-9]{1,10})?$/', $data['f']) !== 1) {
            throw new InvalidArgumentException('Malformed upload.');
        }

        if ($data['at'] < time() - self::LIFETIME || ! is_file(self::directory().DIRECTORY_SEPARATOR.$data['f'])) {
            throw new InvalidArgumentException('This upload has expired.');
        }

        return new self($data['f'], $data['n'], $data['m'], $data['s'], $data['at']);
    }

    /** The signed reference the browser holds, and what it shows of the file. */
    public function toWire(): array
    {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'f' => $this->file,
            'n' => $this->getClientOriginalName(),
            'm' => $this->mime,
            's' => $this->bytes,
            'at' => $this->at,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        $token = $payload.'.'.self::sign($payload);

        return [
            't' => $token,
            'name' => $this->getClientOriginalName(),
            'size' => $this->bytes,
            'mime' => $this->mime,
            'ext' => $this->getClientOriginalExtension(),
            'url' => $this->previewable() ? $this->previewUrl($token) : null,
        ];
    }

    /** A URL that shows the file while it is uploaded, for an image's preview (Livewire's temporaryUrl()). */
    public function temporaryUrl(): string
    {
        if (! $this->previewable()) {
            throw new \RuntimeException("{$this->getClientOriginalName()} is not an image: only images have a temporary URL.");
        }

        return $this->toWire()['url'];
    }

    /** The type found in the file itself when it arrived (not the type the browser claimed). */
    public function getMimeType(): string
    {
        return $this->mime;
    }

    public function getSize(): int
    {
        return $this->bytes;
    }

    public function previewable(): bool
    {
        return in_array($this->mime, self::PREVIEWABLE, true);
    }

    /** Remove uploads older than LIFETIME that no component stored (on each new upload). */
    public static function prune(): void
    {
        foreach (glob(self::directory().DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < time() - self::LIFETIME) {
                @unlink($path);
            }
        }
    }

    /** The file a preview URL names, when its reference is valid and it is an image; null otherwise. */
    public static function forPreview(string $token): ?self
    {
        try {
            $upload = self::fromWire(['t' => $token]);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $upload->previewable() ? $upload : null;
    }

    public static function directory(): string
    {
        return rtrim((string) app(Fusion::class)->config('uploads.directory', storage_path('app/fusion-uploads')), '/\\');
    }

    private function previewUrl(string $token): string
    {
        $endpoint = rtrim((string) app(Fusion::class)->config('endpoint', '/_fusion'), '/');

        return url($endpoint.'/upload/preview').'?t='.$token;
    }

    /** Signed apart from component state: an upload token cannot pass for a snapshot, nor one for it. */
    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'fusion-upload|'.$payload, app(Fusion::class)->key());
    }
}
