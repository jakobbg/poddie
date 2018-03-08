#!/usr/local/bin/bash

pushd () {
    command pushd "$@" > /dev/null
}

popd () {
    command popd "$@" > /dev/null
}

PODDIE_DIR=`dirname $_`
pushd $PODDIE_DIR
/usr/local/bin/php -f ./poddie.php
popd
