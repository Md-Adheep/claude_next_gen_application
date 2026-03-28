#!/bin/bash
# ============================================================
#  deploy.sh — One-command deployment
#  Usage:  ./deploy.sh "your commit message"
#          ./deploy.sh              (auto commit message)
# ============================================================

set -e  # Exit on any error i

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$REPO_DIR"

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  NextGen LMS — Auto Deploy${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

# ── Step 1: Check git repo ────────────────────────────────────
if [ ! -d ".git" ]; then
  echo -e "${RED}✗ Git repo not found. Run: git init && git remote add origin YOUR_REPO_URL${NC}"
  exit 1
fi

# ── Step 2: Check for changes ────────────────────────────────
STATUS=$(git status --porcelain)
UNPUSHED=$(git log origin/main..HEAD --oneline 2>/dev/null)

if [ -z "$STATUS" ] && [ -z "$UNPUSHED" ]; then
  echo -e "${YELLOW}⚠ No changes to deploy. Working tree is clean and up to date.${NC}"
  exit 0
fi

# ── Step 3: Commit message ───────────────────────────────────
if [ -n "$1" ]; then
  MSG="$1"
else
  MSG="deploy: update $(date '+%Y-%m-%d %H:%M')"
fi

if [ -n "$STATUS" ]; then
  echo -e "\n${YELLOW}Changed files:${NC}"
  git status --short
  echo -e "\n${YELLOW}Commit message:${NC} $MSG"

  # ── Step 4: Git add + commit ─────────────────────────────────
  echo -e "\n${BLUE}[1/3] Staging all changes...${NC}"
  git add .

  echo -e "${BLUE}[2/3] Committing...${NC}"
  git commit -m "$MSG"
else
  echo -e "\n${YELLOW}No new file changes — pushing existing commit(s)...${NC}"
  git log origin/main..HEAD --oneline
fi

echo -e "${BLUE}[3/3] Pushing to GitHub (main)...${NC}"
git push origin main

echo -e "\n${GREEN}✓ Pushed to GitHub!${NC}"

# ── Step 5: Wait for Plesk deployment ───────────────────────
echo -e "${BLUE}⏳ GitHub Actions is deploying to Plesk...${NC}"
echo -e "${YELLOW}   Check status: https://github.com/$(git remote get-url origin | sed 's/.*github.com[:/]//' | sed 's/\.git//')/actions${NC}"

echo -e "\n${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  Deployment triggered successfully! 🚀${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"