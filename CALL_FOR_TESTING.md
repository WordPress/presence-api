# Call for Testing: Presence API

The **Presence API** feature plugin is preparing for proposal to WordPress Core as the foundation for real-time awareness and modernized post locking.

Before proposing the feature for core inclusion, we are inviting the community and the Make/Test team to test the admin interfaces and real-time interactions across typical user workflows.

---

## How to Test

You can test immediately in your browser using **WordPress Playground** without setting up a local development environment:

### Option A: Interactive Testing with Simulated Collaborators (Single Browser Window)
This instance runs the companion `presence-scenes` tool to simulate real-time virtual collaborators editing posts alongside you:
👉 **[Launch Playground with Simulated Collaborators](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint-scenes.json)**

### Option B: Multi-User Testing (Two Browser Windows or Incognito)
To test authentic multi-user interaction between two separate sessions:
👉 **[Launch Clean Playground Instance](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint.json)**
*(Open the link in two separate windows or an Incognito window, logged in as different users or tabs).*

---

## Guided Test Tasks

### Task 1: Admin Bar Real-Time Presence
1. Open `wp-admin` and observe the admin bar presence icon.
2. In a second tab or window, navigate between screens (such as Posts, Pages, or Users).
3. **What to verify**:
   - Does the admin bar show active users currently viewing or editing screens?
   - When hovering or focusing via keyboard, are usernames legible and tooltips accurate?

### Task 2: Posts List & "Editors" Column
1. Navigate to **Posts > All Posts**.
2. Open one of the posts in the block editor in another window.
3. Return to the post list.
4. **What to verify**:
   - Does the "Editors" column display an avatar stack for who is actively editing?
   - Does the count and avatar list stay neatly within the table column without overflowing?

### Task 3: Post Locking and Takeover
1. In Window 1, begin editing a post.
2. In Window 2, attempt to edit that same post.
3. **What to verify**:
   - Do you see the post lock notice stating that another user is currently editing?
   - If you click "Take over", does the first window reflect that the lock was transferred?

### Task 4: Stale Screen Notification
1. Keep the **Posts** list open in Tab 1.
2. In Tab 2, edit and publish a change to a post.
3. Return to Tab 1 without manually reloading.
4. **What to verify**:
   - Does the stale-screen notice appear indicating that the content has been updated?
   - Does dismissing the notice keep focus intuitive for accessible navigation?

---

## Questions for Testers

- Were the presence indicators intuitive and non-intrusive?
- Did any interface elements break when resizing the browser window or on mobile/touch?
- Did you experience any sluggishness, unexpected lockouts, or stuck avatars?

---

## Where to Report Feedback

Please report any bugs or design friction:
- As comments on the Make/Test post, or
- Directly on the [Presence API GitHub Issues](https://github.com/WordPress/presence-api/issues) tracker.
