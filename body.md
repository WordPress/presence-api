The idle screenshot still matters because the dashboard shows an “Idle” label even though #591 removed the old idle-dot indicators.

References #593; addresses checklist items 1 and 3.

Tests 06 and 07 now backdate seeded editor rows instead of waiting in real time. The widget marks entries idle after 75 seconds (30 seconds maximum staleness plus the default 45-second idle Heartbeat interval); the default presence read cutoff is 150 seconds. Test 06 asserts that the idle label remains visible, and test 07 backdates rows beyond the read cutoff.

Runtime savings could not be measured locally: wp-env could not start because the WordPress version was not cached, network access was unavailable, and Docker was not running. The test suite itself did not run.

<details>
<summary>Use of AI Tools</summary>

AI assistance: Yes
Tool(s): GitHub Copilot
Model(s): Not exposed by this session
Used for: Checking the plugin thresholds and widget behavior, updating the screenshot test, and validating the change.

</details>
