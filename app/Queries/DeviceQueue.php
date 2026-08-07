<?php

namespace App\Queries;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\EmployeeMap;
use App\Support\PerPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * What this server still owes one clock — the instructions sitting in its
 * mailbox, in plain language.
 *
 * A device never takes an instruction when it is given one: it collects them
 * on its own schedule and reports results later, so the queue is the only place
 * that distinguishes "we decided this" from "the machine has done it". A reader
 * unplugged for a fortnight holds weeks of these, and it is exactly the reader
 * whose People screen looks most settled.
 *
 * The four states are not decoration — they decide what can still be called
 * back. `pending` has not left this server. `sent` is already in the device's
 * hands and cannot be recalled by anything here.
 */
class DeviceQueue
{
    /** Waiting in the mailbox. Nothing has left this server yet. */
    public const PENDING = 'pending';

    /** Handed to the device. Too late to stop; it may or may not have run it. */
    public const SENT = 'sent';

    /** The device carried it out and said so. */
    public const DONE = 'done';

    /** The device tried and reported an error. */
    public const FAILED = 'failed';

    /** Adds a user, or updates the name/card of one already there. */
    public const ADD = 'add';

    /** Deletes a user from the device outright. */
    public const REMOVE = 'remove';

    /** How many instructions sit in each state. */
    public static function counts(Device $device): array
    {
        $byStatus = DeviceCommand::where('device_sn', $device->no_sn)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            self::PENDING => (int) ($byStatus[self::PENDING] ?? 0),
            self::SENT => (int) ($byStatus[self::SENT] ?? 0),
            self::DONE => (int) ($byStatus[self::DONE] ?? 0),
            self::FAILED => (int) ($byStatus[self::FAILED] ?? 0),
            'total' => (int) $byStatus->sum(),
        ];
    }

    /**
     * Two different checkpoints matter: leaving ADMS and hearing back from the
     * clock. Calling both of them "synced" hid the exact failure operators are
     * trying to find, so report them independently.
     *
     * @param  array<string,int>|null  $counts
     */
    public static function progress(Device $device, ?array $counts = null): array
    {
        $counts ??= self::counts($device);
        $total = $counts['total'];
        $delivered = $counts[self::SENT] + $counts[self::DONE] + $counts[self::FAILED];
        $responded = $counts[self::DONE] + $counts[self::FAILED];

        return [
            'total' => $total,
            'delivered' => $delivered,
            'responded' => $responded,
            'delivery_percent' => $total > 0 ? (int) round(($delivered / $total) * 100) : 0,
            'response_percent' => $total > 0 ? (int) round(($responded / $total) * 100) : 0,
        ];
    }

    /** Live row data for the commands currently visible in the browser. */
    public static function liveCommands(Device $device, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DeviceCommand::where('device_sn', $device->no_sn)
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(function (DeviceCommand $command) {
                [$label, $badgeClass] = self::statusLabel($command->status);

                return [$command->id => [
                    'status' => $command->status,
                    'label' => $label,
                    'badge_class' => $badgeClass,
                    'return_code' => $command->return_code,
                    'response' => $command->response,
                    'sent_at' => $command->sent_at?->format('Y-m-d H:i:s'),
                    'done_at' => $command->done_at?->format('Y-m-d H:i:s'),
                ]];
            })
            ->all();
    }

    /**
     * The instructions themselves, newest first, each decoded into what it will
     * actually do to the machine.
     *
     * Names are resolved from employee_map at read time rather than stored on
     * the command: the command carries only what the push protocol needs, and a
     * queue entry for someone since removed from the roster should still say
     * which PIN it affects rather than nothing at all.
     *
     * @param  array{status?:?string,action?:?string}  $filters
     */
    public static function commands(Device $device, array $filters = [], int $perPage = PerPage::DEFAULT): LengthAwarePaginator
    {
        $query = DeviceCommand::where('device_sn', $device->no_sn);

        $status = $filters['status'] ?? null;
        if (in_array($status, [self::PENDING, self::SENT, self::DONE, self::FAILED], true)) {
            $query->where('status', $status);
        }

        // Filtering on the action means filtering on the command text, since that
        // is where the verb lives. DELETE is the only one worth isolating; an
        // operator on this screen is nearly always hunting removals.
        $action = $filters['action'] ?? null;
        if ($action === self::REMOVE) {
            $query->where('body', 'like', '%DELETE%');
        } elseif ($action === self::ADD) {
            $query->where('body', 'not like', '%DELETE%');
        }

        $commands = $query->orderByDesc('id')->paginate($perPage);

        $pins = collect($commands->items())->map(fn ($c) => self::pin($c->body))->filter()->unique();
        $names = $pins->isEmpty()
            ? collect()
            : EmployeeMap::whereIn('device_pin', $pins)->pluck('name', 'device_pin');

        $commands->each(function (DeviceCommand $c) use ($names) {
            $pin = self::pin($c->body);
            $c->setAttribute('pin', $pin);
            $c->setAttribute('person', $pin ? ($names[$pin] ?? null) : null);
            $c->setAttribute('action', self::action($c->body));
            $c->setAttribute('cancellable', $c->status === self::PENDING);
        });

        return $commands;
    }

    /** The device PIN an instruction targets, or null if it isn't about a person. */
    public static function pin(?string $body): ?string
    {
        return preg_match('/PIN=([^\t\r\n]+)/', (string) $body, $m) ? trim($m[1]) : null;
    }

    /** What an instruction does to the machine. */
    public static function action(?string $body): string
    {
        return str_contains((string) $body, 'DELETE') ? self::REMOVE : self::ADD;
    }

    /** Plain-language label + Bootstrap badge class for a state. */
    public static function statusLabel(string $status): array
    {
        return match ($status) {
            self::PENDING => ['Waiting to be collected', 'bg-warning text-dark'],
            self::SENT => ['Handed over — unconfirmed', 'bg-info text-dark'],
            self::DONE => ['Done', 'bg-success'],
            self::FAILED => ['Failed', 'bg-danger'],
            default => [$status, 'bg-secondary'],
        };
    }
}
