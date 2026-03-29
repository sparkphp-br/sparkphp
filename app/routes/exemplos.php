<?php

path('/exemplos')->name('exemplos');

// GET /exemplos — interactive code examples
get(fn() => view('exemplos/index'));
