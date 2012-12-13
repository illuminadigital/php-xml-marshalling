<?php

require_once 'bootstrap.php';

//var_dump($dm->getRepository('Documents\Article'));

$articles = $dm->getRepository('Documents\Article')->findAll();

//var_dump($articles);

foreach ($articles as $article)
{
    echo 'ID: ' . $article->getId() . "\n";
    echo 'Name: ' . $article->getName() . "\n";
    echo "Sections:\n";
    foreach ($article->getSections() as $section)
    {
        echo '  ' . $section->getHeading() . "\n";
        echo '  ' . str_repeat('=', strlen($section->getHeading())) . "\n";
        
        echo '  ' . $section->getContent() . "\n\n";
    }
    echo "\n";
}