<?php

require_once 'bootstrap.php';

$article1 = new Documents\Article();
$article1->setName('Foo');
$article1->setId('MyArticle' . time());

$section1 = new Documents\Section('Part 1', 'The journey begins ....');
$section2 = new Documents\Section('Part 2', 'Something happens ....');
$section3 = new Documents\Section('Part 3', 'Everyone goes home.');

$article1->addSection($section1);
$article1->addSection($section2);
$article1->addSection($section3);

$dm->persist($article1);
$dm->flush();