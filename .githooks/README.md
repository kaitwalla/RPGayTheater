# Git hooks

Install the tracked hooks for a checkout with:

```sh
git config core.hooksPath .githooks
```

The `pre-push` hook runs every check from the GitHub Actions Quality workflow.
