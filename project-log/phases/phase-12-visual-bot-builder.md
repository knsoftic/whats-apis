# Phase 12 — Visual bot flow builder (n8n-style)

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Code + tests (252 passing) · browser-tested with real mouse drag, connect, save and errors |
| **Date** | 2026-11-20 |
| **Requested by user** | Redesign Bot Flows like n8n: drag-and-drop nodes that are connected to each other on a builder canvas. |

## What the builder does (Bot Flows → New / Edit)
- **Canvas**: dotted grid, drag the background to move around, scroll to zoom (30–160 %), zoom buttons and **Fit**.
- **Palette** (left): Message, Menu, Image, Document, Handoff to agent, Go to flow. **Drag a node onto the canvas** or click it. Clicking while a node is selected adds the new node after it and connects it automatically.
- **Connections**: drag from a node's right dot to another node. Lines are curved like n8n. Click a line to select it, then delete it with its 🗑 button or the Delete key.
- **Menu node**: every option has **its own output dot**, so each button leads to its own path. A dot is red until it is connected. Up to 3 options are sent as reply buttons, 4–10 as a list.
- **Settings panel** (right): edit the selected node. Clicking the Trigger opens the flow settings: name, number, trigger type, keywords, match, priority, cooldown.
  - On wide screens the panel sits beside the canvas, and the selected node is panned into view.
  - Nodes can be duplicated and deleted.
- Top bar: flow name, trigger badge, Active switch, Saved/Unsaved state, **Save** (Ctrl+S). Saving stays on the page; a new flow opens in its edit URL.
- **Errors**: the server checks every WhatsApp limit. Broken nodes turn **red** and a list explains each problem, e.g. "Menu 1, option “Price list”: connect it to the next node". Errors about missing connections disappear as soon as you re-connect.
- Works in dark mode; on mobile the palette opens from an "Add" button.

## How the bot runs a visual flow (BotEngine)
- It starts at the Trigger and follows the connections: Message → Image → … .
- A **Menu** sends its buttons and **waits**. The button id is `bf:{flow}:{menuNode}:{option}`, and tapping it continues along that option's connection.
- An option wired straight to **Go to flow** uses the short id `bf:{flow}`. A Go to flow node starts the other flow from its Trigger (at most 5 jumps).
- **Handoff** pauses the bot in that chat (24 h) and ends the flow.
- Safety limit: at most 8 messages per incoming message, so loops stop. Tapping a menu button always answers, even during a cooldown.
- **Older step flows** keep working exactly as before. They open in the canvas as nodes, with menu options shown as "Go to flow" nodes; saving turns them into visual flows.

## Data
- New column `bot_flows.graph` (JSON: `nodes[{id,type,x,y,data}]`, `edges[{from,port,to}]`), migration `2026_11_20_500000`.
- `App\Support\BotGraph`: validate, read, convert old steps, summary for the list ("Message → Menu (3) · 5 nodes").
- `whatsapp.bot.max_nodes = 40`.

## Files
`app/Support/BotGraph.php`, `app/Models/BotFlow.php`, `app/Services/WhatsApp/BotEngine.php`, `app/Http/Controllers/BotFlowController.php`, `resources/js/bot-builder.js` (rewritten), `resources/views/bots/form.blade.php` (rewritten), `resources/views/bots/index.blade.php`.

## Tests
`VisualBotFlowTest` (7) covers:
- connections are followed and each menu option takes its own path (Sales, Support → handoff, Price list → image → text);
- Go to flow from a menu option and inside a chain;
- loop safety limit; cooldown ignored for button taps;
- builder JSON save and update; clear validation errors;
- old step flows still run and open in the canvas.

The existing `BotFlowTest` (8) still passes. **252 passed (1139 assertions).**

## Browser test (local, real mouse)
1. Created a flow: Trigger (keyword) → Message → Menu (Sales / Support / Price list).
2. Dragged Message, Handoff and Image nodes from the palette, and wired each option by dragging from its dot.
3. Moved a node and saved → the edit page reopened with the same layout.
4. Deleted a connection → Menu turned red with a clear error → re-connected → saved.
5. The list showed "Message → Menu (3) · 5 nodes". Dark mode was checked too.
6. The test flow was deleted afterwards.

Not tested live: the bot actually replying on WhatsApp, which needs a real connected number. The engine is covered by the automated tests with Meta faked.
