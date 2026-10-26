package app.haveireadthat

/** Making sense of text shared from another app: a link to a book page, a title, an ISBN. */
object Shared {
    private val url = Regex("""https?://\S+""")
    private val isbn13 = Regex("""97[89](?:[\s-]?\d){10}""")
    private val isbn10 = Regex("""(?<![\dX])\d(?:[\s-]?\d){8}[\s-]?[\dXx](?![\dX])""")

    /** The first valid ISBN anywhere in the text (also inside links), as 13 or 10 characters. */
    fun findIsbn(text: String): String? {
        isbn13.findAll(text).map { it.value.filter(Char::isDigit) }.firstOrNull(::validIsbn13)?.let { return it }
        return isbn10.findAll(text).map { it.value.filter { c -> c.isDigit() || c == 'X' || c == 'x' }.uppercase() }
            .firstOrNull(::validIsbn10)
    }

    /**
     * Words to search for: the shared text without links, or else the readable part of
     * the link (".../de-avonden-gerard-reve/..." becomes "de avonden gerard reve").
     */
    fun searchTerms(text: String): String? {
        val words = url.replace(text, " ").replace(Regex("""\s+"""), " ").trim()
        if (words.count(Char::isLetter) >= 3) return words.take(120)
        val link = url.find(text)?.value ?: return null
        val path = link.substringAfter("://").substringAfter('/').substringBefore('?').substringBefore('#')
        return path.split('/')
            .map { segment -> segment.replace(Regex("""[-_+.]"""), " ").replace(Regex("""\b\d+\b"""), " ").trim() }
            .filter { it.count(Char::isLetter) >= 6 && it.contains(' ') }
            .maxByOrNull { it.length }
            ?.replace(Regex("""\s+"""), " ")
            ?.take(120)
    }

    private fun validIsbn13(code: String): Boolean {
        if (code.length != 13) return false
        val sum = code.take(12).mapIndexed { i, c -> (c - '0') * if (i % 2 == 0) 1 else 3 }.sum()
        return (10 - sum % 10) % 10 == code[12] - '0'
    }

    private fun validIsbn10(code: String): Boolean {
        if (code.length != 10) return false
        val sum = code.mapIndexed { i, c -> (10 - i) * if (c == 'X') 10 else (c - '0') }.sum()
        return code.take(9).all(Char::isDigit) && sum % 11 == 0
    }
}
