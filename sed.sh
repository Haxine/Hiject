#!/bin/sh

#sed -i 's/CachetHQ\\Tests\\Cachet/Gitamin\\Tests/' `grep CachetHQ -rl .`
sed -i 's/kanboard/hiject/' `grep kanboard -rl ./tests`
