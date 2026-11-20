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

## Update 2026-11-20 — Question + Condition nodes
**Question node** (cyan):
- Sends a question and **waits for the customer's next message**, for up to 24 h (`WHATSAPP_BOT_ANSWER_TIMEOUT` minutes).
- Saves the answer as a **variable** (e.g. `topic`) and can also save it to the **contact name / email**.
- Answer check: any text / number / email / phone. A wrong answer gets a retry message; after the last try the flow continues from **✗ No valid answer**.
- Two outputs: **✓ Answer** and **✗ No valid answer**.

**Condition node** (orange, if / else): rules are checked top to bottom, each rule has **its own output**, and there is an **Otherwise** output. Rule kinds:
- **Text / answer**: the customer's message or a saved answer — contains / is exactly / starts with / does not contain / is (not) empty / number greater / less. Several words can be separated by commas (“order, khareed”), and matching is not case-sensitive.
- **Business hours**: days plus from/to time in the server time zone; overnight windows such as 22:00 → 06:00 work.
- **Contact tag**: has / does not have a tag.
- **New contact**: first message in the last 24 h, or returning.

**Variables**: `{{topic}}`, `{{contact_name}}` and `{{phone}}` work in every message, caption, menu text and handoff text. The Message panel has click-to-insert buttons.

**Rules while the bot waits for an answer:**
- The next message is the answer, even if it matches a keyword.
- A tapped bot menu button cancels the question.
- When an agent replies, a handoff happens or the timeout passes, the bot stops waiting.
- Saved answers are stored per chat in `conversations.bot_state` (migration `2026_11_20_600000`).

**Tests:** `BotQuestionConditionTest` (9) covers:
- waiting for and saving the answer, placeholders, saving to contact name and email;
- retry, then the invalid path;
- condition on the saved answer;
- business hours (incl. overnight), tags, new contact, numbers, empty;
- a condition on the incoming message;
- a menu tap cancels a question; timeout; an agent reply clears the wait;
- builder validation;
- **the exact graph built in the browser runs end to end** (fixture `tests/Fixtures/canvas-question-condition.json`).

**261 passed (1219 assertions).**

**Browser test (real mouse):**
- Built Trigger → Message → Question (`topic`) → Condition with rule 1 “{{topic}} contains order, khareed” and rule 2 “Mon–Sat 09:00–18:00” → Message / Message / Handoff.
- Wired the Answer, rule and Otherwise dots, and saved → the edit page reopened intact.
- The variable name was cleaned automatically (`Topic Type!` → `topic_type_`).
- The panel's fade animation was removed so the canvas widens instantly when the panel closes.
