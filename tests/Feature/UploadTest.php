<?php

namespace Nitro\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Models\User;
use Nitro\Tests\TestCase;
use Nitro\Upload;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * nitro:model on a file input: POST /_nitro/upload takes files for a component's upload
 * property, the component gets them as Upload, validates and stores them; previews are images only.
 */
class UploadTest extends TestCase
{
    private function gallery(string $uri = '/gallery'): array
    {
        $response = $this->request('GET', $uri);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->snapshots((string) $response->getContent())[0]['envelope'];
    }

    /** POST /_nitro/upload, as the runtime sends it (FormData). */
    private function upload(array $envelope, string $property, array $files): Response
    {
        $kernel = $this->app->make(HttpKernel::class);
        $request = Request::create('/_nitro/upload', 'POST', ['snapshot' => json_encode($envelope), 'property' => $property], $this->cookies, ['files' => $files], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private function save(array $envelope, array $updates): Response
    {
        return $this->update(['snapshot' => $envelope, 'updates' => $updates, 'call' => ['method' => 'save', 'params' => []]]);
    }

    private function jpeg(string $name = 'Birthday Cake.jpg', int $width = 200, int $height = 100): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    #[DataProvider('frameworks')]
    public function test_a_file_input_uploads_and_the_component_stores_it(string $framework): void
    {
        $this->boot($framework);
        $envelope = $this->gallery();

        $response = $this->upload($envelope, 'photo', [$this->jpeg()]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $wire = $this->json($response)['uploads'][0];
        $this->assertSame(['Birthday Cake.jpg', 'image/jpeg', 'jpg'], [$wire['$u']['name'], $wire['$u']['mime'], $wire['$u']['ext']]);
        $this->assertStringContainsString('/_nitro/upload/preview?t=', $wire['$u']['url']);

        /** The same reference each time it is sent: the server's render and the browser's agree. */
        $upload = Wire::decode($wire);
        $this->assertInstanceOf(Upload::class, $upload);
        $this->assertSame($wire, Wire::encode($upload));

        /** The browser sets the property to it; save() validates and stores it. */
        $response = $this->save($envelope, ['photo' => $wire]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = Wire::fromJson($this->json($response)['snapshot']['state'])['data'];
        $this->assertSame('gallery/cover.jpg', $data['stored']['photo']);
        $this->assertNull($data['photo']);
        $this->assertFileExists(self::APP.'/storage/app/private/gallery/cover.jpg');
    }

    #[DataProvider('frameworks')]
    public function test_several_files_for_an_array_property(string $framework): void
    {
        $this->boot($framework);
        $envelope = $this->gallery();

        $response = $this->upload($envelope, 'photos', [$this->jpeg('one.jpg'), UploadedFile::fake()->create('menu.pdf', 20, 'application/pdf')]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $uploads = $this->json($response)['uploads'];
        $this->assertSame(['one.jpg', 'menu.pdf'], array_map(fn ($u) => $u['$u']['name'], $uploads));
        $this->assertNull($uploads[1]['$u']['url'], 'Only images have a preview.');

        $photo = $this->json($this->upload($envelope, 'photo', [$this->jpeg()]))['uploads'][0];
        $data = Wire::fromJson($this->json($this->save($envelope, ['photo' => $photo, 'photos' => $uploads]))['snapshot']['state'])['data'];
        $this->assertSame(['one.jpg', 'menu.pdf'], $data['stored']['photos']);

        /** More than max_files (3). */
        $this->assertSame(422, $this->upload($envelope, 'photos', [$this->jpeg('a.jpg'), $this->jpeg('b.jpg'), $this->jpeg('c.jpg'), $this->jpeg('d.jpg')])->getStatusCode());
        /** One file for a single upload property. */
        $this->assertSame(422, $this->upload($envelope, 'photo', [$this->jpeg('a.jpg'), $this->jpeg('b.jpg')])->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_the_rules_and_the_components_validation(string $framework): void
    {
        $this->boot($framework);
        $envelope = $this->gallery();

        /** nitro.uploads.rules: max 1024 KB. */
        $response = $this->upload($envelope, 'photo', [UploadedFile::fake()->create('huge.jpg', 2048, 'image/jpeg')]);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('photo', $this->json($response)['errors']);

        /** Accepted by the upload rules, refused by save()'s: $errors, as validate() gives them. */
        $pdf = $this->json($this->upload($envelope, 'photo', [UploadedFile::fake()->create('menu.pdf', 20, 'application/pdf')]))['uploads'][0];
        $state = Wire::fromJson($this->json($this->save($envelope, ['photo' => $pdf]))['snapshot']['state']);
        $this->assertArrayHasKey('photo', $state['memo']['errors'] ?? []);
        $this->assertSame([], $state['data']['stored']);
    }

    #[DataProvider('frameworks')]
    public function test_what_the_upload_endpoint_refuses(string $framework): void
    {
        $this->boot($framework);
        $envelope = $this->gallery();

        /** A changed or made-up snapshot. */
        $renamed = ['state' => str_replace('"pages::gallery"', '"wallet"', $envelope['state']), 'checksum' => $envelope['checksum']];
        $this->assertNotSame($envelope['state'], $renamed['state']);
        $this->assertSame(419, $this->upload($renamed, 'photo', [$this->jpeg()])->getStatusCode());
        $this->assertSame(419, $this->upload(['state' => $envelope['state'], 'checksum' => str_repeat('0', 64)], 'photo', [$this->jpeg()])->getStatusCode());

        /** A partial reload's state (signed, of this page): not a component's whole state, as a #[Server] call refuses it. */
        $this->app->forgetScopedInstances();
        $partial = json_decode((string) $this->request('GET', '/gallery', [
            'HTTP_ACCEPT' => 'application/json', 'HTTP_X_NITRO_NAVIGATE' => '1',
            'HTTP_X_NITRO_PARTIAL' => Wire::fromJson($envelope['state'])['id'], 'HTTP_X_NITRO_ONLY' => 'photo',
        ])->getContent(), true)['partial'];
        $this->assertIsArray($partial, 'the page gave a partial reload');
        $this->app->forgetScopedInstances();
        $this->assertSame(403, $this->upload($partial, 'photo', [$this->jpeg()])->getStatusCode());

        /** A property that does not hold uploads, a locked one, one that is not there. */
        foreach (['caption', 'stored', 'nothing'] as $property) {
            $this->assertSame(403, $this->upload($envelope, $property, [$this->jpeg()])->getStatusCode(), $property);
        }

        /** No file. */
        $this->assertSame(422, $this->upload($envelope, 'photo', [])->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_a_reference_cannot_be_made_up_or_changed(string $framework): void
    {
        $this->boot($framework);
        $envelope = $this->gallery();
        $wire = $this->json($this->upload($envelope, 'photo', [$this->jpeg()]))['uploads'][0];

        [$payload, $signature] = explode('.', $wire['$u']['t']);
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $data['f'] = str_repeat('a', 40).'.php';
        $changed = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=').'.'.$signature;

        foreach ([['t' => $changed], ['t' => 'x.y'], ['t' => 5], 'nope'] as $bad) {
            $response = $this->save($envelope, ['photo' => ['$u' => $bad]]);
            $this->assertSame(400, $response->getStatusCode(), json_encode($bad));
        }

        /** A plain value for an upload property. */
        $this->assertSame(403, $this->save($envelope, ['photo' => 'C:/Windows/win.ini'])->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_uploads_follow_the_pages_authorization(string $framework): void
    {
        $this->boot($framework);
        $user = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
        $this->app->instance('request', Request::create('/'));
        $this->app->make('auth')->guard('web')->setUser($user);
        $envelope = $this->gallery('/private-gallery');

        $this->assertSame(200, $this->upload($envelope, 'photo', [$this->jpeg()])->getStatusCode());

        /** After logout the page's snapshot is not good any more (signed for the user it was made for). */
        $this->app->make('auth')->guard('web')->logout();
        $this->assertContains($this->upload($envelope, 'photo', [$this->jpeg()])->getStatusCode(), [401, 403, 419]);
    }

    #[DataProvider('frameworks')]
    public function test_previews_are_images_only(string $framework): void
    {
        $this->boot($framework);
        $envelope = $this->gallery();

        $image = $this->json($this->upload($envelope, 'photo', [$this->jpeg()]))['uploads'][0]['$u'];
        $response = $this->request('GET', parse_url($image['url'], PHP_URL_PATH).'?'.parse_url($image['url'], PHP_URL_QUERY));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        /** An HTML page named .jpg is text/html by its content: no preview. */
        $html = UploadedFile::fake()->createWithContent('cake.jpg', '<html><script>alert(1)</script></html>');
        $page = $this->json($this->upload($envelope, 'photos', [$html]))['uploads'][0]['$u'];
        $this->assertNull($page['url']);
        $this->assertSame(404, $this->request('GET', '/_nitro/upload/preview?t='.urlencode($page['t']))->getStatusCode());

        $this->assertSame(404, $this->request('GET', '/_nitro/upload/preview?t=x.y')->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_only_upload_properties_take_files(string $framework): void
    {
        $this->boot($framework);
        $photo = $this->json($this->upload($this->gallery(), 'photo', [$this->jpeg()]))['uploads'][0];

        $this->app->forgetScopedInstances();
        $student = $this->snapshots((string) $this->request('GET', '/students/1')->getContent())[0]['envelope'];

        /** $tags is a plain array: no files to it, and no upload in it (only Upload and #[Uploads] properties take them). */
        $this->app->forgetScopedInstances();
        $this->assertSame(403, $this->upload($student, 'tags', [UploadedFile::fake()->create('a.php', 5, 'text/plain')])->getStatusCode());
        $this->app->forgetScopedInstances();
        $this->assertSame(403, $this->update(['snapshot' => $student, 'updates' => ['tags' => [$photo]], 'call' => ['method' => 'order', 'params' => []]])->getStatusCode());
    }
}
