Deploy the NextGen LMS project to GitHub and trigger Plesk auto-deployment.

Follow these steps exactly:

1. Run `git status` to show what files have changed
2. If there are no changes, tell the user "No changes to deploy" and stop
3. Ask the user: "What is your commit message? (or press Enter for auto message)"
4. Use the user's message, or if empty, generate a descriptive commit message based on the changed files
5. Run `git add .`
6. Run `git commit -m "the commit message"`
7. Run `git push origin main`
8. After push succeeds, tell the user:
   - "✅ Pushed to GitHub!"
   - "⏳ GitHub Actions is now deploying to your Plesk server..."
   - "🚀 Your changes will be live in ~30 seconds"
   - Show the GitHub Actions URL: https://github.com/[repo]/actions
9. Optionally run `git log --oneline -3` to show the last 3 commits

If any step fails, show the error clearly and suggest how to fix it.
