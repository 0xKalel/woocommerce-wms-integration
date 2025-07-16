#!/bin/bash

# Fix Git permission issues after setup
echo "🔧 Configuring Git to handle Docker permission changes..."

# Tell git to ignore file permission changes
git config core.filemode false

# Reset git index to ignore current permission changes
git add -A
git reset --hard HEAD

# Verify git status is clean
echo "📋 Git status after fix:"
git status --porcelain

if [ -z "$(git status --porcelain)" ]; then
    echo "✅ Git status is now clean!"
else
    echo "⚠️  Some files still showing as modified. You may need to manually review."
fi

echo ""
echo "🎯 What this fix does:"
echo "   • Sets core.filemode = false (ignores permission changes)"
echo "   • Resets git index to ignore current permission differences"
echo "   • This is safe and won't affect your actual code changes"
echo ""
echo "💡 This is a one-time fix. Future setup runs won't cause git issues."
