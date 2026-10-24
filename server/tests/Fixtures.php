<?php

declare(strict_types=1);

namespace App\Tests;

final class Fixtures
{
    public const STORYGRAPH_CSV = "\xEF\xBB\xBF" . <<<'CSV'
Title,Authors,Contributors,ISBN/UID,Format,Read Status,Date Added,Last Date Read,Dates Read,Read Count,Moods,Pace,Character- or Plot-Driven?,Strong Character Development?,Loveable Characters?,Diverse Characters?,Flawed Characters?,Star Rating,Review,Content Warnings,Content Warning Description,Tags,Owned?
De avonden,Gerard Reve,,9789023417069,paperback,read,2023/01/02,2024/03/09,"2023/01/05-2023/01/20, 2024/03/01-2024/03/09",2,reflective,slow,Character,Yes,No,No,Yes,4.25,"Mooi, ""grijs"" boek.",,,klassiekers,Yes
Project Hail Mary,Andy Weir,,B08FHBV4ZX,ebook,currently-reading,2024/05/01,,2024/05/02-,0,,,,,,,,,,,,,No
Dune,Frank Herbert,,9780441013593,,to-read,2022/12/01,,,0,,,,,,,,,,,,,No
Moby Dick,Herman Melville,,,audio,did-not-finish,2021/06/01,2021/07/01,,0,,,,,,,,,,,,,No
CSV;
}
