<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\WhatsAppAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SplFileObject;

class ContactService
{
    public const MAX_IMPORT_ROWS = 5000;

    /**
     * "vip, lead; delhi" → tag ids (created per account when missing).
     *
     * @return array<int, int>
     */
    public function syncTags(Contact $contact, ?string $tags): array
    {
        $names = collect(preg_split('/[,;]+/', (string) $tags))
            ->map(fn ($t) => Str::limit(trim(preg_replace('/\s+/', ' ', $t)), 50, ''))
            ->filter()
            ->unique(fn ($t) => mb_strtolower($t))
            ->take(20);

        $ids = $names->map(function (string $name) use ($contact) {
            $existing = Tag::where('whatsapp_account_id', $contact->whatsapp_account_id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            return ($existing ?? Tag::create([
                'whatsapp_account_id' => $contact->whatsapp_account_id,
                'name' => $name,
                'color' => Tag::COLORS[abs(crc32(mb_strtolower($name))) % count(Tag::COLORS)],
            ]))->id;
        })->values()->all();

        $contact->tags()->sync($ids);

        return $ids;
    }

    /**
     * Applies opt-in changes, stamping the time only when the status changes.
     */
    public function applyOptIn(Contact $contact, string $status, ?string $source): void
    {
        if ($contact->opt_in_status === $status && $contact->exists) {
            $contact->opt_in_source = $source ?? $contact->opt_in_source;

            return;
        }

        $contact->opt_in_status = $status;
        $contact->opt_in_source = $source;

        if ($status === Contact::OPT_IN) {
            $contact->opted_in_at = now();
        } elseif ($status === Contact::OPT_OUT) {
            $contact->opted_out_at = now();
        }
    }

    /**
     * Import contacts from CSV. Header row required. Recognised columns
     * (case-insensitive): phone*, name, email, tags, opt_in (yes/no/true/false/1/0).
     *
     * @return array{created:int, updated:int, skipped:int, errors: array<int, string>}
     */
    public function import(WhatsAppAccount $account, UploadedFile $file): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        $csv = new SplFileObject($file->getRealPath());
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        $header = null;
        $line = 0;

        foreach ($csv as $row) {
            $line++;

            if (! is_array($row) || $row === [null]) {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $row);

                if (! in_array('phone', $header, true)) {
                    $result['errors'][] = 'The first row must be a header containing a "phone" column.';

                    return $result;
                }

                continue;
            }

            if ($result['created'] + $result['updated'] + $result['skipped'] >= self::MAX_IMPORT_ROWS) {
                $result['errors'][] = 'Stopped after '.self::MAX_IMPORT_ROWS.' rows (maximum per import).';
                break;
            }

            $data = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
            $waId = Contact::normalizeWaId((string) ($data['phone'] ?? ''));

            if (strlen($waId) < 8 || strlen($waId) > 15) {
                $result['skipped']++;
                $this->addError($result, "Row {$line}: invalid phone “".Str::limit((string) ($data['phone'] ?? ''), 30).'”.');

                continue;
            }

            $email = trim((string) ($data['email'] ?? ''));

            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = '';
                $this->addError($result, "Row {$line}: invalid email ignored.");
            }

            DB::transaction(function () use ($account, $waId, $data, $email, &$result) {
                $contact = Contact::firstOrNew(['whatsapp_account_id' => $account->id, 'wa_id' => $waId]);
                $isNew = ! $contact->exists;

                $contact->phone = Contact::formatPhone($waId);

                if (filled($data['name'] ?? null)) {
                    $contact->name = Str::limit(trim((string) $data['name']), 120, '');
                }

                if ($email !== '') {
                    $contact->email = strtolower($email);
                }

                $opt = strtolower(trim((string) ($data['opt_in'] ?? '')));

                if (in_array($opt, ['yes', 'y', 'true', '1', 'opted_in'], true)) {
                    $this->applyOptIn($contact, Contact::OPT_IN, 'csv_import');
                } elseif (in_array($opt, ['no', 'n', 'false', '0', 'opted_out'], true)) {
                    $this->applyOptIn($contact, Contact::OPT_OUT, 'csv_import');
                } elseif ($isNew) {
                    $contact->opt_in_status = Contact::OPT_UNKNOWN;
                }

                $contact->save();

                if (filled($data['tags'] ?? null)) {
                    $existing = $contact->tags()->pluck('name')->implode(',');
                    $this->syncTags($contact, $existing.','.$data['tags']);
                }

                $result[$isNew ? 'created' : 'updated']++;
            });
        }

        return $result;
    }

    /**
     * @param  array{created:int, updated:int, skipped:int, errors: array<int, string>}  $result
     */
    private function addError(array &$result, string $error): void
    {
        if (count($result['errors']) < 25) {
            $result['errors'][] = $error;
        }
    }

    /**
     * Streams a CSV export. Cells starting with = + - @ are prefixed with ' to
     * prevent formula injection when opened in Excel.
     *
     * @param  Builder<Contact>  $query
     */
    public function exportCsv(Builder $query, $output): void
    {
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($output, ['name', 'phone', 'wa_id', 'email', 'whatsapp_profile_name', 'tags', 'opt_in_status', 'opt_in_source', 'opted_in_at', 'opted_out_at', 'whatsapp_account', 'created_at']);

        $query->with(['tags:id,name', 'account:id,name'])->orderBy('id')->chunk(500, function ($contacts) use ($output, $safe) {
            foreach ($contacts as $c) {
                fputcsv($output, array_map($safe, [
                    $c->name, $c->phone, $c->wa_id, $c->email, $c->profile_name,
                    $c->tags->pluck('name')->implode('; '),
                    $c->opt_in_status, $c->opt_in_source,
                    $c->opted_in_at?->toDateTimeString(), $c->opted_out_at?->toDateTimeString(),
                    $c->account?->name, $c->created_at?->toDateTimeString(),
                ]));
            }
        });
    }
}
