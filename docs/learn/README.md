# Learn presence

Presence is a set of rows in one table, each keyed by its room and client and counted as present until it expires. Writers keep their rows alive by writing them again, readers skip the expired ones, and nobody has to say goodbye.

Every lesson adds a writer, adds a reader, or explains a rule about rows. When something in the code is confusing, ask which of the three it is.

1. [See it](see-it.md)
2. [Why a table](why-a-table.md)
3. [The row](the-row.md)
4. [Rooms and client IDs](rooms.md)
5. [Writers](writers.md)
6. [Readers and signals](readers.md)
7. [The browser](browser.md)
8. [Features are consumers](features.md)
9. [Edges](edges.md)
10. [Capstone](capstone.md)

The lessons use a local site from `npx wp-env start` (see [Run locally](https://github.com/WordPress/presence-api#run-locally)), which also activates Presence Scenes and the Presence Debugger.
