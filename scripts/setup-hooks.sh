#!/bin/bash

ICON_CHECKMARK="\033[32m✓\033[0m"
ICON_CROSS="\033[31m✗\033[0m"

# Directory of the script
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
APP_DIR="$(dirname "$SCRIPT_DIR")"
GIT_DIR="$APP_DIR/.git"
if [ ! -f "$GIT_DIR" ]; then
    # echo ".git directory not found checking parent directory"
    GIT_DIR="$(dirname "$APP_DIR")/.git"
    
    if [ ! -f "$GIT_DIR" || ! -f "$GIT_DIR/../.gitmodules" ]; then
        echo -e "$ICON_CROSS $APP_DIR is neither a git repository nor a git submodule, aborting"
        exit 1
    fi
fi

# Function to convert relative path to absolute path
# Arguments:
#   $1: Relative path to convert
# Returns:
#   Absolute path
get_absolute_path() {
    local relative_path="$1"
    if command -v realpath >/dev/null 2>&1; then
        realpath "$relative_path"
    else
        # Fallback for systems without realpath
        local absolute_path
        absolute_path="$(cd "$(dirname "$relative_path")" && pwd)/$(basename "$relative_path")"
        echo "$absolute_path"
    fi
}

echo "Installing git hooks in $APP_DIR"

HOOKS_DIR=$(get_absolute_path "$GIT_DIR/hooks")
if [ ! -d "$HOOKS_DIR" ]; then
    mkdir -p "$HOOKS_DIR"
fi


# Make all hook scripts executable
chmod +x "$SCRIPT_DIR"/*.sh
chmod +x "$SCRIPT_DIR"/hooks/*

# Check if hooks directory exists and contains files
if [ ! -d "$SCRIPT_DIR/hooks" ] || [ -z "$(ls -A "$SCRIPT_DIR/hooks")" ]; then
    echo -e "   $ICON_CROSS No hooks found"
else
    # Create symlinks for each hook
    for hook in "$SCRIPT_DIR"/hooks/*; do
        hook_name=$(basename "$hook")
        if [ "$hook_name" != "install" ]; then
            if [ -L "$HOOKS_DIR/$hook_name" ] && [ "$(readlink "$HOOKS_DIR/$hook_name")" = "$hook" ]; then
                echo -e "    $ICON_CHECKMARK Hook already set: $hook_name"
            else
                ln -sf "$(get_absolute_path "$hook")" "$HOOKS_DIR/$hook_name" && \
                echo -e "    $ICON_CHECKMARK Installed $hook_name hook" || \
                echo -e "    $ICON_CROSS Failed to install $hook_name hook"
            fi
        fi
    done
fi
