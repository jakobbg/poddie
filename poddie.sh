#!/usr/local/bin/bash

PHP_BINARY=/usr/local/bin/php
BASEDIR=$(dirname "$0")

pushd () {
    command pushd "$BASEDIR" > /dev/null
}

popd () {
    command popd > /dev/null
}

pushd $BASEDIR
$PHP_BINARY -f ./poddie.php
popd
