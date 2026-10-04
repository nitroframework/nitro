<?php

namespace Nitro\Fusion\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use JsonException;
use Nitro\Fusion\Compiler\PropertyMeta;
use Nitro\Fusion\Fusion;
use Nitro\Fusion\Rendering\Snapshot;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Upload;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * POST {endpoint}/upload: files chosen in a fusion:model file input, before the component uses
 * them. The request carries the component's signed snapshot and the property the input binds, so
 * a file is only taken for a component this application rendered, on a page the visitor may still
 * see, for a property that holds uploads:
 *
 *   snapshot  the signed envelope (419 when changed or foreign)
 *   property  a public, unlocked ?Upload property (one file) or array property (files) (403)
 *   files[]   each checked against fusion.uploads.rules (422 with the messages)
 *
 * The answer is {"uploads": [<signed reference>, ...]}: the browser sets the property to them.
 *
 * GET {endpoint}/upload/preview?t=...: an uploaded image, for $photo->temporaryUrl(). Only raster
 * images (the type found in the file), sent so the browser cannot take them for anything else.
 */
final class UploadController
{
    public function __construct(
        private readonly PersistentMiddleware $persistent,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $envelope = json_decode((string) $request->input('snapshot'), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Malformed upload.');
        }

        $snapshot = Snapshot::fromEnvelope($envelope, $this->fusion()->stateKeys(), $this->fusion()->stateLifetime());
        $snapshot = $snapshot->withName($this->fusion()->componentName($snapshot->name));

        if (! $this->fusion()->has($snapshot->name)) {
            throw new HttpException(404, 'Unknown component.');
        }

        $this->persistent->check($snapshot->memo['route'] ?? [], $request);

        $name = $request->input('property');
        $meta = $this->fusion()->meta($snapshot->name);
        /** By the browser's name for it (an obfuscated build). */
        $name = is_string($name) ? ($this->fusion()->names()?->propertyName($name, array_keys($meta->properties)) ?? $name) : $name;
        $property = is_string($name) ? $meta->property($name) : null;
        $multiple = $property !== null && in_array('array', $property->types, true);

        if ($property === null || $property->locked || ! ($property->kind === PropertyMeta::UPLOAD || $multiple)) {
            throw new HttpException(403, 'This property does not take uploads.');
        }

        $files = $request->file('files');
        $files = is_array($files) ? array_values($files) : [];
        $max = $multiple ? (int) $this->fusion()->config('uploads.max_files', 20) : 1;

        if ($files === [] || count($files) > $max || array_filter($files, fn ($f) => ! $f instanceof UploadedFile) !== []) {
            throw new HttpException(422, $files === [] ? 'No file was received.' : "Choose up to {$max} ".($max === 1 ? 'file' : 'files').'.');
        }

        $rules = (array) $this->fusion()->config('uploads.rules', ['file', 'max:12288']);
        $validator = Validator::make(['files' => $files], ['files.*' => $rules], [], ['files.*' => $name]);

        if ($validator->fails()) {
            /** Keyed by the browser's name for the property (an obfuscated build). */
            $field = $this->fusion()->names()?->property($name) ?? $name;

            return new JsonResponse(['message' => $validator->errors()->first(), 'errors' => [$field => array_values(array_unique($validator->errors()->all()))]], 422);
        }

        Upload::prune();

        return new JsonResponse(['uploads' => array_map(fn (UploadedFile $file) => Wire::encode(Upload::receive($file)), $files)]);
    }

    public function preview(Request $request): BinaryFileResponse
    {
        $upload = Upload::forPreview((string) $request->query('t', ''));

        if ($upload === null) {
            throw new HttpException(404, 'Not found.');
        }

        return (new BinaryFileResponse($upload->getRealPath(), 200, [
            'Content-Type' => $upload->getMimeType(),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ]))->setContentDisposition('inline', 'preview.'.(preg_replace('/[^a-z0-9]/', '', strtolower($upload->getClientOriginalExtension())) ?: 'img'));
    }

    /**
     * This request's Fusion. The router keeps a controller between requests (Octane, Nitro's
     * workers): Fusion, bound per request, is not kept with it.
     */
    private function fusion(): Fusion
    {
        return app(Fusion::class);
    }
}
