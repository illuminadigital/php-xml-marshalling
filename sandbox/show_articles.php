<?php

require_once 'bootstrap.php';

$articles = $dm->getRepository('Documents\Article')->findAll();

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