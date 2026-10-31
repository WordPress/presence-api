# See it

Watch rows appear and disappear before reading how they work.

## Try

Open the dashboard at `localhost:8888/wp-admin/` in one tab and play a scene in a terminal:

```
npx wp-env run cli wp presence scene run editing-together
```

Two actors arrive, open the same post and leave. Watch the faces in the admin bar and the Active Posts widget, then list the rows behind them:

```
npx wp-env run cli wp presence list admin/online
npx wp-env run cli wp presence summary
```

Open the Presence Debugger from the admin bar to see each room's rows as the scene plays.

## Common mistake

Presence is not a live connection to each person. The faces are rows that someone wrote recently, and the next lessons are about who writes them and when they stop counting.

## Check yourself

<details>
<summary>You close your tab. When does your face leave everyone else's admin bar?</summary>

Usually at once, since the closing tab asks the server to remove its row. If that request never arrives, as when the network drops, the face goes when the row expires, which [The row](the-row.md) covers.

</details>
