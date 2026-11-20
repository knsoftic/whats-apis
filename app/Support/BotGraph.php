<?php

namespace App\Support;

use App\Models\BotFlow;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Visual bot flow = nodes + connections (n8n-style), stored in bot_flows.graph.
 *
 *  { "nodes": [ {id, type, x, y, data}, … ],
 *    "edges": [ {from, port, to}, … ] }
 *
 * Node types and their output ports:
 *   trigger   → out                       (exactly one per flow; settings live on the flow)
 *   text      → out                       data: text
 *   image     → out                       data: url, caption
 *   document  → out                       data: url, caption, filename
 *   menu      → one port per option id    data: text, footer, button_label, options[{id,title,description}]
 *   handoff   → (none, ends the flow)     data: text
 *   goto      → (none, opens another flow) data: flow_id
 */
class BotGraph
{
    public const TYPES = ['trigger', 'text', 'menu', 'image', 'document', 'handoff', 'goto'];

    public const LABELS = [
        'trigger' => 'Trigger', 'text' => 'Message', 'menu' => 'Menu', 'image' => 'Image',
        'document' => 'Document', 'handoff' => 'Handoff to agent', 'goto' => 'Go to flow',
    ];

    private const ID = '/^[A-Za-z0-9_-]{1,24}$/';

    // ------------------------------------------------------------------ reading

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>|null
     */
    public static function node(array $graph, string $id): ?array
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['id'] ?? null) === $id) {
                return $node;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $graph */
    public static function triggerId(array $graph): ?string
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['type'] ?? null) === 'trigger') {
                return $node['id'];
            }
        }

        return null;
    }

    /** Node connected to (from, port), if any. */
    public static function next(array $graph, ?string $from, string $port = 'out'): ?string
    {
        if ($from === null) {
            return null;
        }

        foreach ($graph['edges'] ?? [] as $edge) {
            if (($edge['from'] ?? null) === $from && ($edge['port'] ?? 'out') === $port) {
                return $edge['to'] ?? null;
            }
        }

        return null;
    }

    /** Short description for lists, e.g. "Message → Menu (3) · 6 nodes". */
    public static function summary(array $graph): string
    {
        $path = [];
        $current = self::next($graph, self::triggerId($graph));
        $seen = [];

        while ($current && ! isset($seen[$current]) && count($path) < 4) {
            $seen[$current] = true;
            $node = self::node($graph, $current);

            if (! $node) {
                break;
            }

            $path[] = self::LABELS[$node['type']].($node['type'] === 'menu' ? ' ('.count($node['data']['options'] ?? []).')' : '');
            $current = $node['type'] === 'menu' ? null : self::next($graph, $current);
        }

        $count = max(0, count($graph['nodes'] ?? []) - 1);

        return ($path ? implode(' → ', $path) : 'Not connected yet').' · '.$count.' '.Str::plural('node', $count);
    }

    // --------------------------------------------------------------- converting

    /**
     * Older linear flows ("steps") → graph, so they open in the visual builder.
     * Steps after a menu are placed but left unconnected (a menu waits for a tap).
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    public static function fromSteps(array $steps): array
    {
        $nodes = [['id' => 'start', 'type' => 'trigger', 'x' => 60, 'y' => 200, 'data' => []]];
        $edges = [];
        $prev = 'start';
        $x = 360;
        $gotoY = 60;

        foreach (array_values($steps) as $i => $step) {
            $type = $step['type'] ?? 'text';
            $id = 'n'.($i + 1);

            if (! in_array($type, ['text', 'menu', 'image', 'document', 'handoff'], true)) {
                continue;
            }

            $data = match ($type) {
                'text', 'handoff' => ['text' => (string) ($step['text'] ?? '')],
                'image' => ['url' => (string) ($step['url'] ?? ''), 'caption' => (string) ($step['caption'] ?? '')],
                'document' => ['url' => (string) ($step['url'] ?? ''), 'caption' => (string) ($step['caption'] ?? ''), 'filename' => (string) ($step['filename'] ?? '')],
                'menu' => [
                    'text' => (string) ($step['text'] ?? ''),
                    'footer' => (string) ($step['footer'] ?? ''),
                    'button_label' => (string) ($step['button_label'] ?? ''),
                    'options' => [],
                ],
            };

            if ($type === 'menu') {
                foreach (array_values($step['options'] ?? []) as $j => $option) {
                    $optionId = 'o'.($j + 1);
                    $data['options'][] = ['id' => $optionId, 'title' => (string) ($option['title'] ?? ''), 'description' => (string) ($option['description'] ?? '')];

                    if (! empty($option['flow_id'])) {
                        $gotoId = $id.'g'.($j + 1);
                        $nodes[] = ['id' => $gotoId, 'type' => 'goto', 'x' => $x + 330, 'y' => $gotoY, 'data' => ['flow_id' => (int) $option['flow_id']]];
                        $edges[] = ['from' => $id, 'port' => $optionId, 'to' => $gotoId];
                        $gotoY += 130;
                    }
                }
            }

            $nodes[] = ['id' => $id, 'type' => $type, 'x' => $x, 'y' => 180, 'data' => $data];

            if ($prev !== null) {
                $edges[] = ['from' => $prev, 'port' => 'out', 'to' => $id];
            }

            $prev = in_array($type, ['menu', 'handoff'], true) ? null : $id;
            $x += $type === 'menu' ? 660 : 330;
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /** A new flow: trigger → empty message. */
    public static function starter(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'trigger', 'x' => 60, 'y' => 200, 'data' => []],
                ['id' => 'n1', 'type' => 'text', 'x' => 380, 'y' => 200, 'data' => ['text' => '']],
            ],
            'edges' => [['from' => 'start', 'port' => 'out', 'to' => 'n1']],
        ];
    }

    // --------------------------------------------------------------- validation

    /**
     * Validates editor input against WhatsApp limits and returns a clean graph.
     * Errors are thrown as "graph" validation messages naming the node.
     *
     * @return array<string, mixed>
     */
    public static function validate(mixed $graph, WhatsAppAccount $account, ?BotFlow $flow = null): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['graph' => $message]);

        if (! is_array($graph) || ! is_array($graph['nodes'] ?? null) || ! is_array($graph['edges'] ?? null)) {
            $fail('The flow could not be read — please reload the page.');
        }

        $maxNodes = (int) config('whatsapp.bot.max_nodes', 40);

        if (count($graph['nodes']) > $maxNodes + 1) {
            $fail("A flow can have at most {$maxNodes} nodes.");
        }

        $flowIds = BotFlow::where('whatsapp_account_id', $account->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $errors = [];
        $nodes = [];
        $ports = [];      // node id → allowed output ports
        $labels = [];     // node id → "Menu 2" (for messages)
        $counter = [];

        foreach ($graph['nodes'] as $raw) {
            $id = (string) ($raw['id'] ?? '');
            $type = (string) ($raw['type'] ?? '');

            if (! preg_match(self::ID, $id) || isset($nodes[$id]) || ! in_array($type, self::TYPES, true)) {
                $fail('The flow contains an invalid node — please reload the page.');
            }

            $counter[$type] = ($counter[$type] ?? 0) + 1;
            $label = self::LABELS[$type].($type === 'trigger' ? '' : ' '.$counter[$type]);
            $labels[$id] = $label;
            $d = is_array($raw['data'] ?? null) ? $raw['data'] : [];
            $s = fn (string $key) => trim((string) ($d[$key] ?? ''));

            $data = [];
            $ports[$id] = ['out'];

            switch ($type) {
                case 'trigger':
                    break;

                case 'text':
                    $data = ['text' => $s('text')];
                    if ($data['text'] === '' || mb_strlen($data['text']) > 4096) {
                        $errors[] = "{$label}: message text is required (max 4096 characters).";
                    }
                    break;

                case 'image':
                case 'document':
                    $data = ['url' => $s('url'), 'caption' => $s('caption')];
                    if ($type === 'document') {
                        $data['filename'] = Str::limit($s('filename'), 120, '');
                    }
                    if (! filter_var($data['url'], FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($data['url']), 'https://') || mb_strlen($data['url']) > 2000) {
                        $errors[] = "{$label}: enter a public https:// link to the ".($type === 'image' ? 'JPG/PNG image' : 'document').'.';
                    }
                    if (mb_strlen($data['caption']) > 1024) {
                        $errors[] = "{$label}: caption max 1024 characters.";
                    }
                    break;

                case 'menu':
                    $options = [];
                    foreach (array_slice(is_array($d['options'] ?? null) ? $d['options'] : [], 0, 11) as $o) {
                        $oid = (string) ($o['id'] ?? '');
                        if (! preg_match(self::ID, $oid) || $oid === 'out' || isset($options[$oid])) {
                            $fail('The flow contains an invalid menu option — please reload the page.');
                        }
                        $options[$oid] = ['id' => $oid, 'title' => trim((string) ($o['title'] ?? '')), 'description' => trim((string) ($o['description'] ?? ''))];
                    }
                    $options = array_values($options);
                    $titleMax = count($options) <= 3 ? 20 : 24;
                    $data = ['text' => $s('text'), 'footer' => $s('footer'), 'button_label' => $s('button_label'), 'options' => $options];

                    if ($data['text'] === '' || mb_strlen($data['text']) > 1024) {
                        $errors[] = "{$label}: menu message is required (max 1024 characters).";
                    }
                    if (count($options) < 1 || count($options) > 10) {
                        $errors[] = "{$label}: a menu needs 1 to 10 options.";
                    }
                    if (mb_strlen($data['footer']) > 60 || mb_strlen($data['button_label']) > 20) {
                        $errors[] = "{$label}: footer max 60, list button label max 20 characters.";
                    }
                    foreach ($options as $j => $o) {
                        if ($o['title'] === '' || mb_strlen($o['title']) > $titleMax) {
                            $errors[] = "{$label}, option ".($j + 1).": title is required (max {$titleMax} characters".($titleMax === 20 ? ' for buttons' : ' for list rows').').';
                        }
                        if (mb_strlen($o['description']) > 72) {
                            $errors[] = "{$label}, option ".($j + 1).': description max 72 characters.';
                        }
                    }
                    $ports[$id] = array_column($options, 'id');
                    break;

                case 'handoff':
                    $data = ['text' => $s('text')];
                    if (mb_strlen($data['text']) > 1024) {
                        $errors[] = "{$label}: message max 1024 characters.";
                    }
                    $ports[$id] = [];
                    break;

                case 'goto':
                    $target = (int) ($d['flow_id'] ?? 0);
                    $data = ['flow_id' => $target];
                    if (! in_array($target, $flowIds, true) || ($flow && $target === $flow->id)) {
                        $errors[] = "{$label}: choose another flow of the same WhatsApp number.";
                    }
                    $ports[$id] = [];
                    break;
            }

            $nodes[$id] = [
                'id' => $id,
                'type' => $type,
                'x' => (int) max(-5000, min(20000, round((float) ($raw['x'] ?? 0)))),
                'y' => (int) max(-5000, min(20000, round((float) ($raw['y'] ?? 0)))),
                'data' => $data,
            ];
        }

        $triggers = array_keys(array_filter($nodes, fn ($n) => $n['type'] === 'trigger'));

        if (count($triggers) !== 1) {
            $fail('A flow needs exactly one Trigger node.');
        }

        if (count($nodes) < 2) {
            $fail('Add at least one message after the Trigger.');
        }

        // Connections: valid ends, one connection per output port, nothing into the trigger.
        $edges = [];
        foreach (array_slice($graph['edges'], 0, 500) as $e) {
            $from = (string) ($e['from'] ?? '');
            $to = (string) ($e['to'] ?? '');
            $port = (string) ($e['port'] ?? 'out');

            if (! isset($nodes[$from], $nodes[$to]) || $from === $to || $nodes[$to]['type'] === 'trigger' || ! in_array($port, $ports[$from], true)) {
                continue; // stale / impossible connection → drop
            }

            $edges[$from.'|'.$port] = ['from' => $from, 'port' => $port, 'to' => $to];
        }
        $edges = array_values($edges);
        $connected = fn (string $from, string $port) => collect($edges)->contains(fn ($e) => $e['from'] === $from && $e['port'] === $port);

        if (! $connected($triggers[0], 'out')) {
            $errors[] = 'Connect the Trigger to the first message (drag from its right dot).';
        }

        foreach ($nodes as $id => $node) {
            if ($node['type'] !== 'menu') {
                continue;
            }
            foreach ($node['data']['options'] as $j => $o) {
                if (! $connected($id, $o['id'])) {
                    $errors[] = "{$labels[$id]}, option “".($o['title'] ?: $j + 1).'”: connect it to the next node (drag from the dot next to the option).';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['graph' => array_values(array_unique($errors))]);
        }

        return ['nodes' => array_values($nodes), 'edges' => $edges];
    }
}
