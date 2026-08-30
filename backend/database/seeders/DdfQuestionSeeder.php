<?php

namespace Database\Seeders;

use App\Models\DdfQuestion;
use Illuminate\Database\Seeder;

/** The static general-knowledge question pool for "Der Dümmste fliegt", in English and German. */
class DdfQuestionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([...$this->englishQuestions(), ...$this->germanQuestions()] as $row) {
            DdfQuestion::query()->firstOrCreate(
                ['text' => $row['text'], 'language' => $row['language']],
                $row,
            );
        }
    }

    /** @return list<array{category: string, language: string, text: string, correct_answer: string}> */
    private function englishQuestions(): array
    {
        $rows = [
            // History
            ['category' => 'history', 'text' => 'In what year did the Berlin Wall fall?', 'correct_answer' => '1989'],
            ['category' => 'history', 'text' => 'Who was the first President of the United States?', 'correct_answer' => 'George Washington'],
            ['category' => 'history', 'text' => 'In what year did World War II end?', 'correct_answer' => '1945'],
            ['category' => 'history', 'text' => 'Which ancient civilization built the pyramids of Giza?', 'correct_answer' => 'The Egyptians'],
            ['category' => 'history', 'text' => 'Who was the first man to walk on the Moon?', 'correct_answer' => 'Neil Armstrong'],
            ['category' => 'history', 'text' => 'Which empire was ruled by Julius Caesar?', 'correct_answer' => 'The Roman Empire'],
            ['category' => 'history', 'text' => 'In what year did the Titanic sink?', 'correct_answer' => '1912'],

            // Geography
            ['category' => 'geography', 'text' => 'What is the capital of Australia?', 'correct_answer' => 'Canberra'],
            ['category' => 'geography', 'text' => 'Which river is the longest in the world?', 'correct_answer' => 'The Nile'],
            ['category' => 'geography', 'text' => 'What is the smallest country in the world?', 'correct_answer' => 'Vatican City'],
            ['category' => 'geography', 'text' => 'Which desert is the largest in the world?', 'correct_answer' => 'The Sahara'],
            ['category' => 'geography', 'text' => 'What is the capital of Canada?', 'correct_answer' => 'Ottawa'],
            ['category' => 'geography', 'text' => 'Which mountain is the tallest in the world?', 'correct_answer' => 'Mount Everest'],
            ['category' => 'geography', 'text' => 'How many continents are there?', 'correct_answer' => '7'],

            // Science
            ['category' => 'science', 'text' => 'What is the chemical symbol for gold?', 'correct_answer' => 'Au'],
            ['category' => 'science', 'text' => 'How many bones are in the adult human body?', 'correct_answer' => '206'],
            ['category' => 'science', 'text' => 'What planet is known as the Red Planet?', 'correct_answer' => 'Mars'],
            ['category' => 'science', 'text' => 'What gas do plants absorb from the atmosphere?', 'correct_answer' => 'Carbon dioxide'],
            ['category' => 'science', 'text' => 'What is the boiling point of water in Celsius?', 'correct_answer' => '100'],
            ['category' => 'science', 'text' => 'What force pulls objects toward the Earth?', 'correct_answer' => 'Gravity'],
            ['category' => 'science', 'text' => 'What is the powerhouse of the cell called?', 'correct_answer' => 'The mitochondria'],

            // Math
            ['category' => 'math', 'text' => 'What is the square root of 144?', 'correct_answer' => '12'],
            ['category' => 'math', 'text' => 'How many sides does a hexagon have?', 'correct_answer' => '6'],
            ['category' => 'math', 'text' => 'What is 9 multiplied by 9?', 'correct_answer' => '81'],
            ['category' => 'math', 'text' => 'What is the value of Pi rounded to two decimal places?', 'correct_answer' => '3.14'],
            ['category' => 'math', 'text' => 'How many degrees are in a right angle?', 'correct_answer' => '90'],
            ['category' => 'math', 'text' => 'What do you call a number that can only be divided by 1 and itself?', 'correct_answer' => 'A prime number'],
            ['category' => 'math', 'text' => 'How many zeros are in one million?', 'correct_answer' => '6'],

            // Sports
            ['category' => 'sports', 'text' => 'How many players are on a standard soccer team on the field?', 'correct_answer' => '11'],
            ['category' => 'sports', 'text' => 'In which sport would you perform a slam dunk?', 'correct_answer' => 'Basketball'],
            ['category' => 'sports', 'text' => 'How often are the Summer Olympic Games held?', 'correct_answer' => 'Every 4 years'],
            ['category' => 'sports', 'text' => 'In tennis, what is a score of zero called?', 'correct_answer' => 'Love'],
            ['category' => 'sports', 'text' => 'How many rings are on the Olympic flag?', 'correct_answer' => '5'],
            ['category' => 'sports', 'text' => 'What sport is played at Wimbledon?', 'correct_answer' => 'Tennis'],
            ['category' => 'sports', 'text' => 'How many players are on a basketball team on the court at once?', 'correct_answer' => '5'],

            // Movies & TV
            ['category' => 'movies_tv', 'text' => 'Who directed the movie "Jaws"?', 'correct_answer' => 'Steven Spielberg'],
            ['category' => 'movies_tv', 'text' => 'What is the name of the coffee shop in the show "Friends"?', 'correct_answer' => 'Central Perk'],
            ['category' => 'movies_tv', 'text' => 'Which movie features a shark terrorizing a beach town called Amity?', 'correct_answer' => 'Jaws'],
            ['category' => 'movies_tv', 'text' => 'Who played Iron Man in the Marvel Cinematic Universe?', 'correct_answer' => 'Robert Downey Jr.'],
            ['category' => 'movies_tv', 'text' => 'What is the name of the wizarding school in Harry Potter?', 'correct_answer' => 'Hogwarts'],
            ['category' => 'movies_tv', 'text' => 'Which animated film features a clownfish named Marlin searching for his son?', 'correct_answer' => 'Finding Nemo'],
            ['category' => 'movies_tv', 'text' => 'What is the highest-grossing film of all time (as of its release, unadjusted)?', 'correct_answer' => 'Avatar'],

            // Music
            ['category' => 'music', 'text' => 'Which band released the album "Abbey Road"?', 'correct_answer' => 'The Beatles'],
            ['category' => 'music', 'text' => 'How many strings does a standard guitar have?', 'correct_answer' => '6'],
            ['category' => 'music', 'text' => 'Who is known as the "King of Pop"?', 'correct_answer' => 'Michael Jackson'],
            ['category' => 'music', 'text' => 'What instrument has 88 keys?', 'correct_answer' => 'The piano'],
            ['category' => 'music', 'text' => 'Which composer went deaf later in life yet kept composing?', 'correct_answer' => 'Ludwig van Beethoven'],
            ['category' => 'music', 'text' => 'What does "DJ" stand for?', 'correct_answer' => 'Disc Jockey'],
            ['category' => 'music', 'text' => 'Which country is credited with inventing the accordion?', 'correct_answer' => 'Germany'],

            // Animals
            ['category' => 'animals', 'text' => 'What is the fastest land animal?', 'correct_answer' => 'The cheetah'],
            ['category' => 'animals', 'text' => 'How many legs does a spider have?', 'correct_answer' => '8'],
            ['category' => 'animals', 'text' => 'What is the largest mammal in the world?', 'correct_answer' => 'The blue whale'],
            ['category' => 'animals', 'text' => 'What do you call a baby kangaroo?', 'correct_answer' => 'A joey'],
            ['category' => 'animals', 'text' => 'How many hearts does an octopus have?', 'correct_answer' => '3'],
            ['category' => 'animals', 'text' => 'What is a group of lions called?', 'correct_answer' => 'A pride'],
            ['category' => 'animals', 'text' => 'Which bird is known for its inability to fly but is a strong swimmer?', 'correct_answer' => 'The penguin'],

            // Technology
            ['category' => 'technology', 'text' => 'What does "HTTP" stand for?', 'correct_answer' => 'HyperText Transfer Protocol'],
            ['category' => 'technology', 'text' => 'Who is credited with founding Microsoft?', 'correct_answer' => 'Bill Gates'],
            ['category' => 'technology', 'text' => 'What does "CPU" stand for?', 'correct_answer' => 'Central Processing Unit'],
            ['category' => 'technology', 'text' => 'What company created the iPhone?', 'correct_answer' => 'Apple'],
            ['category' => 'technology', 'text' => 'What does "WWW" stand for?', 'correct_answer' => 'World Wide Web'],
            ['category' => 'technology', 'text' => 'What does "AI" stand for?', 'correct_answer' => 'Artificial Intelligence'],
            ['category' => 'technology', 'text' => 'Which company developed the Android operating system?', 'correct_answer' => 'Google'],

            // Culture
            ['category' => 'culture', 'text' => 'Which country is famous for inventing pizza in its modern form?', 'correct_answer' => 'Italy'],
            ['category' => 'culture', 'text' => 'What is the traditional Japanese art of paper folding called?', 'correct_answer' => 'Origami'],
            ['category' => 'culture', 'text' => 'Which country celebrates Bastille Day?', 'correct_answer' => 'France'],
            ['category' => 'culture', 'text' => 'What is the national sport of Japan?', 'correct_answer' => 'Sumo wrestling'],
            ['category' => 'culture', 'text' => 'Which festival of lights is celebrated by Hindus?', 'correct_answer' => 'Diwali'],
            ['category' => 'culture', 'text' => 'What is the traditional dress of Scotland called?', 'correct_answer' => 'The kilt'],
            ['category' => 'culture', 'text' => 'Which country is famous for the tea ceremony tradition?', 'correct_answer' => 'Japan'],

            // Everyday knowledge
            ['category' => 'everyday_knowledge', 'text' => 'How many days are there in a leap year?', 'correct_answer' => '366'],
            ['category' => 'everyday_knowledge', 'text' => 'How many hours are there in a full day?', 'correct_answer' => '24'],
            ['category' => 'everyday_knowledge', 'text' => 'What color do you get by mixing blue and yellow?', 'correct_answer' => 'Green'],
            ['category' => 'everyday_knowledge', 'text' => 'How many months have 31 days?', 'correct_answer' => '7'],
            ['category' => 'everyday_knowledge', 'text' => 'What is the freezing point of water in Celsius?', 'correct_answer' => '0'],
            ['category' => 'everyday_knowledge', 'text' => 'How many minutes are there in an hour?', 'correct_answer' => '60'],
            ['category' => 'everyday_knowledge', 'text' => 'What do you call a word that reads the same forwards and backwards?', 'correct_answer' => 'A palindrome'],
        ];

        return array_map(fn (array $row) => [...$row, 'language' => 'en'], $rows);
    }

    /** @return list<array{category: string, language: string, text: string, correct_answer: string}> */
    private function germanQuestions(): array
    {
        $rows = [
            // Geschichte
            ['category' => 'history', 'text' => 'In welchem Jahr fiel die Berliner Mauer?', 'correct_answer' => '1989'],
            ['category' => 'history', 'text' => 'Wer war der erste Präsident der Vereinigten Staaten?', 'correct_answer' => 'George Washington'],
            ['category' => 'history', 'text' => 'In welchem Jahr endete der Zweite Weltkrieg?', 'correct_answer' => '1945'],
            ['category' => 'history', 'text' => 'Welche antike Zivilisation erbaute die Pyramiden von Gizeh?', 'correct_answer' => 'Die Ägypter'],
            ['category' => 'history', 'text' => 'Wer war der erste Mensch auf dem Mond?', 'correct_answer' => 'Neil Armstrong'],
            ['category' => 'history', 'text' => 'Welches Reich wurde von Julius Cäsar regiert?', 'correct_answer' => 'Das Römische Reich'],
            ['category' => 'history', 'text' => 'In welchem Jahr sank die Titanic?', 'correct_answer' => '1912'],

            // Geografie
            ['category' => 'geography', 'text' => 'Wie heißt die Hauptstadt von Australien?', 'correct_answer' => 'Canberra'],
            ['category' => 'geography', 'text' => 'Welcher Fluss ist der längste der Welt?', 'correct_answer' => 'Der Nil'],
            ['category' => 'geography', 'text' => 'Welches ist das kleinste Land der Welt?', 'correct_answer' => 'Vatikanstadt'],
            ['category' => 'geography', 'text' => 'Welche Wüste ist die größte der Welt?', 'correct_answer' => 'Die Sahara'],
            ['category' => 'geography', 'text' => 'Wie heißt die Hauptstadt von Kanada?', 'correct_answer' => 'Ottawa'],
            ['category' => 'geography', 'text' => 'Welcher Berg ist der höchste der Welt?', 'correct_answer' => 'Der Mount Everest'],
            ['category' => 'geography', 'text' => 'Wie viele Kontinente gibt es?', 'correct_answer' => '7'],

            // Wissenschaft
            ['category' => 'science', 'text' => 'Welches chemische Symbol steht für Gold?', 'correct_answer' => 'Au'],
            ['category' => 'science', 'text' => 'Wie viele Knochen hat ein erwachsener Mensch?', 'correct_answer' => '206'],
            ['category' => 'science', 'text' => 'Welcher Planet wird als der Rote Planet bezeichnet?', 'correct_answer' => 'Mars'],
            ['category' => 'science', 'text' => 'Welches Gas nehmen Pflanzen aus der Atmosphäre auf?', 'correct_answer' => 'Kohlendioxid'],
            ['category' => 'science', 'text' => 'Bei wie viel Grad Celsius kocht Wasser?', 'correct_answer' => '100'],
            ['category' => 'science', 'text' => 'Welche Kraft zieht Objekte zur Erde?', 'correct_answer' => 'Die Schwerkraft'],
            ['category' => 'science', 'text' => 'Wie nennt man das Kraftwerk der Zelle?', 'correct_answer' => 'Die Mitochondrien'],

            // Mathematik
            ['category' => 'math', 'text' => 'Was ist die Quadratwurzel von 144?', 'correct_answer' => '12'],
            ['category' => 'math', 'text' => 'Wie viele Seiten hat ein Sechseck?', 'correct_answer' => '6'],
            ['category' => 'math', 'text' => 'Was ist 9 mal 9?', 'correct_answer' => '81'],
            ['category' => 'math', 'text' => 'Wie lautet die Zahl Pi, gerundet auf zwei Nachkommastellen?', 'correct_answer' => '3,14'],
            ['category' => 'math', 'text' => 'Wie viele Grad hat ein rechter Winkel?', 'correct_answer' => '90'],
            ['category' => 'math', 'text' => 'Wie nennt man eine Zahl, die nur durch 1 und sich selbst teilbar ist?', 'correct_answer' => 'Eine Primzahl'],
            ['category' => 'math', 'text' => 'Wie viele Nullen hat eine Million?', 'correct_answer' => '6'],

            // Sport
            ['category' => 'sports', 'text' => 'Wie viele Spieler stehen bei einer Fußballmannschaft auf dem Feld?', 'correct_answer' => '11'],
            ['category' => 'sports', 'text' => 'In welcher Sportart gibt es einen Slam Dunk?', 'correct_answer' => 'Basketball'],
            ['category' => 'sports', 'text' => 'Wie oft finden die Olympischen Sommerspiele statt?', 'correct_answer' => 'Alle 4 Jahre'],
            ['category' => 'sports', 'text' => 'Wie nennt man beim Tennis den Punktestand null?', 'correct_answer' => 'Love'],
            ['category' => 'sports', 'text' => 'Wie viele Ringe hat die Olympische Flagge?', 'correct_answer' => '5'],
            ['category' => 'sports', 'text' => 'Welche Sportart wird in Wimbledon gespielt?', 'correct_answer' => 'Tennis'],
            ['category' => 'sports', 'text' => 'Wie viele Spieler stehen bei einer Basketballmannschaft gleichzeitig auf dem Feld?', 'correct_answer' => '5'],

            // Filme & TV
            ['category' => 'movies_tv', 'text' => 'Wer führte bei dem Film "Der weiße Hai" Regie?', 'correct_answer' => 'Steven Spielberg'],
            ['category' => 'movies_tv', 'text' => 'Wie heißt das Café in der Serie "Friends"?', 'correct_answer' => 'Central Perk'],
            ['category' => 'movies_tv', 'text' => 'In welchem Film terrorisiert ein Hai die Stadt Amity?', 'correct_answer' => 'Der weiße Hai'],
            ['category' => 'movies_tv', 'text' => 'Wer spielte Iron Man im Marvel Cinematic Universe?', 'correct_answer' => 'Robert Downey Jr.'],
            ['category' => 'movies_tv', 'text' => 'Wie heißt die Zauberschule in Harry Potter?', 'correct_answer' => 'Hogwarts'],
            ['category' => 'movies_tv', 'text' => 'In welchem Animationsfilm sucht ein Clownfisch namens Marlin seinen Sohn?', 'correct_answer' => 'Findet Nemo'],
            ['category' => 'movies_tv', 'text' => 'Welcher Film war (zum Zeitpunkt seines Erscheinens, unbereinigt) der erfolgreichste Film aller Zeiten an den Kinokassen?', 'correct_answer' => 'Avatar'],

            // Musik
            ['category' => 'music', 'text' => 'Welche Band veröffentlichte das Album "Abbey Road"?', 'correct_answer' => 'Die Beatles'],
            ['category' => 'music', 'text' => 'Wie viele Saiten hat eine normale Gitarre?', 'correct_answer' => '6'],
            ['category' => 'music', 'text' => 'Wer wird als "King of Pop" bezeichnet?', 'correct_answer' => 'Michael Jackson'],
            ['category' => 'music', 'text' => 'Welches Instrument hat 88 Tasten?', 'correct_answer' => 'Das Klavier'],
            ['category' => 'music', 'text' => 'Welcher Komponist wurde im späteren Leben taub und komponierte trotzdem weiter?', 'correct_answer' => 'Ludwig van Beethoven'],
            ['category' => 'music', 'text' => 'Wofür steht die Abkürzung "DJ"?', 'correct_answer' => 'Disc Jockey'],
            ['category' => 'music', 'text' => 'Welchem Land wird die Erfindung des Akkordeons zugeschrieben?', 'correct_answer' => 'Deutschland'],

            // Tiere
            ['category' => 'animals', 'text' => 'Welches ist das schnellste Landtier?', 'correct_answer' => 'Der Gepard'],
            ['category' => 'animals', 'text' => 'Wie viele Beine hat eine Spinne?', 'correct_answer' => '8'],
            ['category' => 'animals', 'text' => 'Welches ist das größte Säugetier der Welt?', 'correct_answer' => 'Der Blauwal'],
            ['category' => 'animals', 'text' => 'Wie nennt man ein Baby-Känguru?', 'correct_answer' => 'Joey'],
            ['category' => 'animals', 'text' => 'Wie viele Herzen hat ein Oktopus?', 'correct_answer' => '3'],
            ['category' => 'animals', 'text' => 'Wie nennt man eine Gruppe von Löwen?', 'correct_answer' => 'Ein Rudel'],
            ['category' => 'animals', 'text' => 'Welcher Vogel kann nicht fliegen, ist aber ein starker Schwimmer?', 'correct_answer' => 'Der Pinguin'],

            // Technik
            ['category' => 'technology', 'text' => 'Wofür steht die Abkürzung "HTTP"?', 'correct_answer' => 'HyperText Transfer Protocol'],
            ['category' => 'technology', 'text' => 'Wer gilt als Gründer von Microsoft?', 'correct_answer' => 'Bill Gates'],
            ['category' => 'technology', 'text' => 'Wofür steht die Abkürzung "CPU"?', 'correct_answer' => 'Central Processing Unit'],
            ['category' => 'technology', 'text' => 'Welches Unternehmen entwickelte das iPhone?', 'correct_answer' => 'Apple'],
            ['category' => 'technology', 'text' => 'Wofür steht die Abkürzung "WWW"?', 'correct_answer' => 'World Wide Web'],
            ['category' => 'technology', 'text' => 'Wofür steht die Abkürzung "KI"?', 'correct_answer' => 'Künstliche Intelligenz'],
            ['category' => 'technology', 'text' => 'Welches Unternehmen entwickelte das Betriebssystem Android?', 'correct_answer' => 'Google'],

            // Kultur
            ['category' => 'culture', 'text' => 'Welches Land gilt als Erfinder der modernen Pizza?', 'correct_answer' => 'Italien'],
            ['category' => 'culture', 'text' => 'Wie heißt die traditionelle japanische Kunst des Papierfaltens?', 'correct_answer' => 'Origami'],
            ['category' => 'culture', 'text' => 'Welches Land feiert den Bastille-Tag?', 'correct_answer' => 'Frankreich'],
            ['category' => 'culture', 'text' => 'Was ist der Nationalsport Japans?', 'correct_answer' => 'Sumo-Ringen'],
            ['category' => 'culture', 'text' => 'Welches Lichterfest wird von Hindus gefeiert?', 'correct_answer' => 'Diwali'],
            ['category' => 'culture', 'text' => 'Wie heißt die traditionelle Tracht Schottlands?', 'correct_answer' => 'Der Kilt'],
            ['category' => 'culture', 'text' => 'Welches Land ist für seine Teezeremonie-Tradition bekannt?', 'correct_answer' => 'Japan'],

            // Alltagswissen
            ['category' => 'everyday_knowledge', 'text' => 'Wie viele Tage hat ein Schaltjahr?', 'correct_answer' => '366'],
            ['category' => 'everyday_knowledge', 'text' => 'Wie viele Stunden hat ein Tag?', 'correct_answer' => '24'],
            ['category' => 'everyday_knowledge', 'text' => 'Welche Farbe entsteht, wenn man Blau und Gelb mischt?', 'correct_answer' => 'Grün'],
            ['category' => 'everyday_knowledge', 'text' => 'Wie viele Monate haben 31 Tage?', 'correct_answer' => '7'],
            ['category' => 'everyday_knowledge', 'text' => 'Bei wie viel Grad Celsius gefriert Wasser?', 'correct_answer' => '0'],
            ['category' => 'everyday_knowledge', 'text' => 'Wie viele Minuten hat eine Stunde?', 'correct_answer' => '60'],
            ['category' => 'everyday_knowledge', 'text' => 'Wie nennt man ein Wort, das vorwärts und rückwärts gelesen gleich ist?', 'correct_answer' => 'Ein Palindrom'],
        ];

        return array_map(fn (array $row) => [...$row, 'language' => 'de'], $rows);
    }
}
