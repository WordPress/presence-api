# See it

Watch rows appear and disappear before reading how they work.

## Try

Open the dashboard at `localhost:8888/wp-admin/` in one tab and play a scene in a terminal:

```
npx wp-env run cli wp presence scene run editing-together
```

For about fifty seconds an author and an editor work in the same post, then leave. Watch the faces in the admin bar and the Active Posts widget, and list the rows behind them from a second terminal while it plays:

```
npx wp-env run cli wp presence list admin/online
npx wp-env run cli wp presence summary
```

Click the heart in the admin bar, the debugger, to see every row in the rooms you are in.

No local site? [Presence Scenes in Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint-scenes.json) plays the same scene on the Posts screen, with the debugger's heart in the admin bar.

## Common mistake

Presence is not a live connection to each person. The faces are rows that someone wrote recently, and the next lessons are about who writes them and when they stop counting.

## Check yourself

<details>
<summary>You close your tab. When does your face leave everyone else's admin bar?</summary>

Usually at once, since the closing tab asks the server to remove its row. If that request never arrives, as when the network drops, the face goes when the row expires, which [The row](the-row.md) covers.

</details>
