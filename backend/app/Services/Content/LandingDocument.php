<?php

declare(strict_types=1);

namespace App\Services\Content;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LandingDocument
{
    public const SECTIONS = ['about', 'activities', 'gallery', 'faq', 'contact'];

    public function empty(): array
    {
        return [
            'schema_version' => 1,
            'header' => ['login_label' => 'Masuk ke Aplikasi', 'nav_labels' => array_combine(self::SECTIONS, ['Tentang', 'Kegiatan', 'Galeri', 'FAQ', 'Kontak'])],
            'hero' => ['eyebrow' => '', 'title' => '', 'description' => '', 'login_label' => 'Masuk ke Aplikasi', 'image' => null],
            'about' => ['title' => '', 'description' => '', 'items' => []],
            'activities' => ['title' => '', 'description' => '', 'items' => []],
            'gallery' => ['title' => '', 'items' => []],
            'faq' => ['title' => '', 'items' => []],
            'contact' => ['title' => '', 'description' => '', 'email' => '', 'phone' => '', 'socials' => []],
            'footer' => ['text' => ''],
            'seo' => ['title' => '', 'description' => '', 'image' => null],
            'sections' => array_map(fn (string $key): array => ['key' => $key, 'visible' => true], self::SECTIONS),
        ];
    }

    public function validate(array $content): array
    {
        $plain = static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && (preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value) || preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value))) {
                $fail('Gunakan teks biasa tanpa markup atau karakter kontrol.');
            }
        };
        $text = fn (int $max): array => ['present', 'nullable', 'string', 'max:'.$max, $plain];
        $rules = [
            'content' => ['required', 'array:schema_version,header,hero,about,activities,gallery,faq,contact,footer,seo,sections'],
            'content.schema_version' => ['required', 'integer', Rule::in([1])],
            'content.header' => ['required', 'array:login_label,nav_labels'],
            'content.header.login_label' => $text(60),
            'content.header.nav_labels' => ['required', 'array:about,activities,gallery,faq,contact'],
            'content.hero' => ['required', 'array:eyebrow,title,description,login_label,image'],
            'content.hero.eyebrow' => $text(80),
            'content.hero.title' => $text(150),
            'content.hero.description' => $text(1000),
            'content.hero.login_label' => $text(60),
            'content.gallery' => ['required', 'array:title,items'],
            'content.gallery.title' => $text(100),
            'content.gallery.items' => ['present', 'array', 'list', 'max:24'],
            'content.gallery.items.*' => ['required', 'array:media_id,alt,caption'],
            'content.gallery.items.*.media_id' => ['required', 'uuid'],
            'content.gallery.items.*.alt' => $text(180),
            'content.gallery.items.*.caption' => $text(300),
            'content.faq' => ['required', 'array:title,items'],
            'content.faq.title' => $text(100),
            'content.faq.items' => ['present', 'array', 'list', 'max:20'],
            'content.faq.items.*' => ['required', 'array:question,answer'],
            'content.faq.items.*.question' => $text(200),
            'content.faq.items.*.answer' => $text(2000),
            'content.contact' => ['required', 'array:title,description,email,phone,socials'],
            'content.contact.title' => $text(100),
            'content.contact.description' => $text(1000),
            'content.contact.email' => ['present', 'nullable', 'string', 'email:rfc', 'max:254'],
            'content.contact.phone' => ['present', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{7,32}$/D'],
            'content.contact.socials' => ['present', 'array', 'list', 'max:8'],
            'content.contact.socials.*' => ['required', 'array:label,url'],
            'content.contact.socials.*.label' => $text(60),
            'content.contact.socials.*.url' => ['present', 'nullable', 'string', 'max:500', 'url:https', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || $value === '') {
                    return;
                }
                $parts = parse_url($value);
                if ($parts === false || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\s<>\x00-\x1F]/', $value)) {
                    $fail('Gunakan URL HTTPS tanpa credential atau karakter kontrol.');
                }
            }],
            'content.footer' => ['required', 'array:text'],
            'content.footer.text' => $text(500),
            'content.seo' => ['required', 'array:title,description,image'],
            'content.seo.title' => $text(70),
            'content.seo.description' => $text(180),
            'content.sections' => ['required', 'array', 'list', 'size:5'],
            'content.sections.*' => ['required', 'array:key,visible'],
            'content.sections.*.key' => ['required', Rule::in(self::SECTIONS), 'distinct:strict'],
            'content.sections.*.visible' => ['required', 'boolean'],
        ];
        foreach (self::SECTIONS as $key) {
            $rules['content.header.nav_labels.'.$key] = $text(40);
        }
        foreach (['about', 'activities'] as $key) {
            $rules['content.'.$key] = ['required', 'array:title,description,items'];
            $rules['content.'.$key.'.title'] = $text(100);
            $rules['content.'.$key.'.description'] = $text(2000);
            $rules['content.'.$key.'.items'] = ['present', 'array', 'list', 'max:12'];
            $rules['content.'.$key.'.items.*'] = ['required', 'array:title,description'];
            $rules['content.'.$key.'.items.*.title'] = $text(100);
            $rules['content.'.$key.'.items.*.description'] = $text(1000);
        }
        foreach (['hero', 'seo'] as $key) {
            $rules['content.'.$key.'.image'] = ['present', 'nullable', 'array:media_id,alt,caption', 'required_array_keys:media_id,alt,caption'];
            $rules['content.'.$key.'.image.media_id'] = ['required_with:content.'.$key.'.image', 'uuid'];
            $rules['content.'.$key.'.image.alt'] = ['sometimes', 'nullable', 'string', 'max:180', $plain];
            // The parent requires these keys when it is an object; null means no image.
            $rules['content.'.$key.'.image.caption'] = ['sometimes', 'nullable', 'string', 'max:300', $plain];
        }
        $validated = Validator::make(['content' => $content], $rules, [
            'required' => 'Field ini wajib diisi.', 'present' => 'Field ini harus disertakan.',
            'array' => 'Gunakan struktur konten yang didukung.', 'max' => 'Isi atau jumlah item terlalu panjang.',
            'uuid' => 'Pilih gambar yang tersedia.', 'email' => 'Masukkan alamat email yang valid.',
            'url' => 'Gunakan URL HTTPS yang valid.', 'string' => 'Gunakan teks biasa.',
            'in' => 'Pilihan tidak didukung.', 'distinct' => 'Section tidak boleh berulang.',
            'size' => 'Sertakan kelima section tepat satu kali.', 'list' => 'Gunakan daftar berurutan.',
            'boolean' => 'Pilihan tampil/sembunyi tidak valid.', 'integer' => 'Versi konten tidak valid.',
            'required_array_keys' => 'Struktur gambar belum lengkap.',
        ])->validate()['content'];
        $validated['schema_version'] = 1;

        return $this->normalize($validated);
    }

    private function normalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            } elseif ($item === null && $key !== 'image') {
                $value[$key] = '';
            } elseif (is_string($item)) {
                $value[$key] = $key === 'media_id' ? strtolower(trim($item)) : trim($item);
            } elseif ($key === 'visible') {
                $value[$key] = (bool) $item;
            }
        }

        return $value;
    }

    public function mediaIds(array $content): array
    {
        $ids = array_column($content['gallery']['items'], 'media_id');
        foreach (['hero', 'seo'] as $key) {
            if ($content[$key]['image'] !== null) {
                $ids[] = $content[$key]['image']['media_id'];
            }
        }
        sort($ids);

        return array_values(array_unique($ids));
    }

    public function snapshot(array $draft): array
    {
        $content = $this->validate($draft);
        $errors = [];
        foreach (['header.login_label', 'hero.title', 'hero.description', 'hero.login_label', 'seo.title', 'seo.description'] as $path) {
            if (data_get($content, $path) === '') {
                $errors['content.'.$path] = ['Lengkapi field ini sebelum publikasi.'];
            }
        }
        $empty = $this->empty();
        foreach ($content['sections'] as &$section) {
            $key = $section['key'];
            $data = $content[$key];
            $available = $data['title'] !== '' && match ($key) {
                'about', 'activities' => $data['description'] !== '' || count($data['items']) > 0,
                'gallery', 'faq' => count($data['items']) > 0,
                'contact' => $data['description'] !== '' || $data['email'] !== '' || $data['phone'] !== '' || count($data['socials']) > 0,
            };
            $section['visible'] = $section['visible'] && $available;
            if (! $section['visible']) {
                $content[$key] = $empty[$key];
                $content['header']['nav_labels'][$key] = '';
            } elseif ($content['header']['nav_labels'][$key] === '') {
                $errors['content.header.nav_labels.'.$key] = ['Label navigasi wajib diisi untuk section yang tampil.'];
            }
        }
        unset($section);
        foreach (['about', 'activities'] as $key) {
            foreach ($content[$key]['items'] as $i => $item) {
                foreach (['title', 'description'] as $field) {
                    if ($item[$field] === '') {
                        $errors["content.$key.items.$i.$field"] = ['Lengkapi item atau hapus dari draft.'];
                    }
                }
            }
        }
        foreach ($content['faq']['items'] as $i => $item) {
            foreach (['question', 'answer'] as $field) {
                if ($item[$field] === '') {
                    $errors["content.faq.items.$i.$field"] = ['Lengkapi pertanyaan dan jawaban.'];
                }
            }
        }
        foreach ($content['contact']['socials'] as $i => $item) {
            foreach (['label', 'url'] as $field) {
                if ($item[$field] === '') {
                    $errors["content.contact.socials.$i.$field"] = ['Lengkapi label dan URL.'];
                }
            }
        }
        foreach (['hero', 'seo'] as $key) {
            if ($content[$key]['image'] !== null && $content[$key]['image']['alt'] === '') {
                $errors['content.'.$key.'.image.alt'] = ['Alt text wajib diisi untuk gambar.'];
            }
        }
        foreach ($content['gallery']['items'] as $i => $item) {
            if ($item['alt'] === '') {
                $errors["content.gallery.items.$i.alt"] = ['Alt text wajib diisi untuk gambar.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $content;
    }
}
