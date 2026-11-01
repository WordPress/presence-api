# Call for Testing: Presence API

The **Presence API** feature plugin team is preparing for a proposal to merge into WordPress Core as the foundation for real-time awareness and modernized post locking.

We invite the community and the Make/Test team to test the admin interfaces and real-time interactions across typical admin workflows. Feedback is open through **November 15, 2026**.

---

## What is Being Tested?

- **Real-Time Awareness**: Live presence indicators across the admin toolbar, posts list, and user screens.
- **Modernized Post Locks**: Post locks look and behave identically to standard WordPress core post locking, but their storage has moved off post meta and into the dedicated `wp_presence` table ([#548](https://github.com/WordPress/presence-api/issues/548)).
- **Build Version**: The Playground blueprints test the latest `preview-main` build (built from the `main` branch).

---

## How to Test

You can test directly in your browser using **WordPress Playground** without any local environment setup:

### Option A: Interactive Testing with Simulated Collaborators (Recommended)
This instance runs the companion `presence-scenes` tool, which simulates real-time virtual users editing posts and navigating the admin:
👉 **[Launch Playground with Simulated Collaborators](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint-scenes.json)**

### Option B: Playground with Demo Data
This instance comes pre-seeded with 100 demo posts and users, along with a demo helper button in the admin to simulate saves from other users:
👉 **[Launch Playground with Demo Data](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint.json)**
*(Note: Because Playground stores site state locally in your browser storage, opening two separate browser windows or an incognito tab creates independent Playground sites. To test multi-user actions, please use the simulated collaborators in Option A or the helper button in Option B).*

---

## Guided Test Tasks

### Task 1: Admin Bar Real-Time Presence
*Use Option A (Simulated Collaborators).*

1. Open `wp-admin` and look at the presence/heart icon in the top toolbar.
2. Observe the counter updating as simulated collaborators move across screens.
3. Hover or use keyboard navigation (Tab) to open the presence menu.
4. **What to verify**:
   - The toolbar shows other active users (your own user is intentionally excluded from the count).
   - User avatars and names are legible, tooltips render correctly, and keyboard focus works properly.

### Task 2: Posts List & "Editors" Column
*Use Option A with the "Editing together" scene (`/wp-admin/edit.php?presence_scene=editing-together`).*

1. Navigate to **Posts > All Posts**.
2. Locate posts that simulated collaborators are actively editing.
3. **What to verify**:
   - The **Editors** column shows an avatar stack indicating active editors.
   - Hovering or navigating into the row with a keyboard reveals collaborator names.
   - On smaller screens (or responsive mobile view), avatars adjust without causing horizontal overflow.

### Task 3: Post Locking and Takeover
*Use Option A with the "Editing together" scene.*

1. In **Posts > All Posts**, click to edit a post that another collaborator is currently editing.
2. Observe the post lock dialog.
3. Click **Take over**.
4. **What to verify**:
   - The lock dialog indicates that another user is currently editing the post.
   - Taking over grants you editing access smoothly, transferring the lock row in `wp_presence`.

### Task 4: Stale Screen Notification
*Use Option B (Demo Data).*

1. Navigate to **Settings > General** (or open an existing post in the editor).
2. Click the floating **Pretend another user saved** helper button (or save the setting via a second tab).
3. **What to verify**:
   - A yellow stale-screen notification appears indicating that someone else has saved changes to this screen.
   - When dismissing the notice, keyboard focus smoothly moves to the screen heading.
   *(Note: The stale-screen notice is designed for individual edit screens and settings pages, not the main Posts list).*

---

## What to Check

- **Accessibility**: Can you navigate and operate all presence indicators using only a keyboard?
- **Responsiveness**: Do views on phones or tablets display cleanly without broken layouts? (See [#830](https://github.com/WordPress/presence-api/pull/830) for reference phone layouts).
- **Usability**: Are presence indicators informative without being distracting?

---

## Known Issues

- The current open issues list is tracked on [GitHub Issues](https://github.com/WordPress/presence-api/issues?q=is%3Aissue+is%3Aopen).
- Network-wide presence screens require multisite mode.

---

## How to Report Feedback

Please share your testing report following the [Make/Test Reporting format](https://make.wordpress.org/test/handbook/call-for-testing/#testing-reports):

1. **Environment**: Device, OS, Browser & version.
2. **Tasks Tested**: Which scenarios you walked through.
3. **Findings**: Any bugs, unexpected behaviors, layout glitches, or design friction.
4. **Screenshots/Console**: Attach screenshots or any browser console errors encountered.

You can post feedback:
- As comments on the Make/Test post, or
- Directly on the [Presence API GitHub Issues](https://github.com/WordPress/presence-api/issues) tracker with the `#needs-testing` tag.
