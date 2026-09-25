<?xml version="1.0" encoding="UTF-8"?>
<!--
  Feuille de style du sitemap.xml : transforme le XML brut en page HTML
  lisible pour les humains. Les moteurs de recherche ignorent cette feuille
  et lisent le XML tel quel.
-->
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">

  <xsl:output method="html" encoding="UTF-8" indent="no"/>

  <xsl:template match="/s:urlset">
    <html lang="fr">
      <head>
        <meta charset="utf-8"/>
        <meta name="viewport" content="width=device-width, initial-scale=1"/>
        <title>Plan du site — sitemap.xml</title>
        <style>
          :root { color-scheme: light dark; }
          * { box-sizing: border-box; }
          body {
            margin: 0;
            padding: 2rem 1rem;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f6f7fb;
            color: #1d2330;
          }
          .wrap { max-width: 880px; margin: 0 auto; }
          h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
          .sub { margin: 0 0 1.25rem; color: #5b6472; font-size: .95rem; }
          .card {
            background: #fff;
            border: 1px solid #e3e6ee;
            border-radius: 12px;
            overflow: hidden;
          }
          table { width: 100%; border-collapse: collapse; font-size: .92rem; }
          th, td { padding: .55rem .8rem; text-align: left; vertical-align: top; }
          thead th {
            background: #eef1f7;
            color: #3c4454;
            font-weight: 600;
            border-bottom: 1px solid #e3e6ee;
            white-space: nowrap;
          }
          tbody tr + tr td { border-top: 1px solid #eef0f5; }
          tbody tr:hover { background: #f7f9ff; }
          td.loc a {
            color: #1a56db;
            text-decoration: none;
            word-break: break-all;
          }
          td.loc a:hover { text-decoration: underline; }
          td.num { white-space: nowrap; }
          .prio {
            display: inline-block;
            min-width: 2.4em;
            text-align: center;
            border-radius: 999px;
            padding: .1rem .5rem;
            font-size: .8rem;
            font-weight: 600;
            background: #e8edf6;
            color: #3c4454;
          }
          .prio.high { background: #dcfce7; color: #166534; }
          .prio.mid  { background: #dbeafe; color: #1e40af; }
          .prio.low  { background: #f1f2f6; color: #6b7280; }
          .imgs { color: #5b6472; font-size: .85rem; }
          .muted { color: #8a91a0; }
          footer { margin-top: 1rem; color: #8a91a0; font-size: .8rem; }
          @media (prefers-color-scheme: dark) {
            body { background: #14171f; color: #e6e9f0; }
            .card { background: #1c202b; border-color: #2a3040; }
            thead th { background: #232937; color: #c7cdd9; border-color: #2a3040; }
            tbody tr + tr td { border-color: #232937; }
            tbody tr:hover { background: #202636; }
            td.loc a { color: #7fb0ff; }
            .prio { background: #2a3040; color: #c7cdd9; }
            .prio.high { background: #14351f; color: #86efac; }
            .prio.mid  { background: #17284a; color: #93c5fd; }
            .prio.low  { background: #232937; color: #9aa1af; }
            .sub, .imgs, .muted, footer { color: #9aa1af; }
          }
          @media (max-width: 640px) {
            th:nth-child(3), td:nth-child(3),
            th:nth-child(5), td:nth-child(5) { display: none; }
          }
        </style>
      </head>
      <body>
        <div class="wrap">
          <h1>Plan du site</h1>
          <p class="sub">
            Fichier <code>sitemap.xml</code> —
            <xsl:value-of select="count(s:url)"/> URL&#160;indexable(s),
            destiné aux moteurs de recherche. Cette vue est uniquement
            destinée à la lecture humaine.
          </p>
          <div class="card">
            <table>
              <thead>
                <tr>
                  <th>Page</th>
                  <th>Priorité</th>
                  <th>Fréquence</th>
                  <th>Dernière modif.</th>
                  <th>Images</th>
                </tr>
              </thead>
              <tbody>
                <xsl:for-each select="s:url">
                  <tr>
                    <td class="loc">
                      <a href="{s:loc}"><xsl:value-of select="s:loc"/></a>
                    </td>
                    <td class="num">
                      <xsl:variable name="p" select="normalize-space(s:priority)"/>
                      <span>
                        <xsl:attribute name="class">
                          <xsl:text>prio </xsl:text>
                          <xsl:choose>
                            <xsl:when test="$p = '1.0' or $p = '0.9'">high</xsl:when>
                            <xsl:when test="$p = '0.8' or $p = '0.7' or $p = '0.6'">mid</xsl:when>
                            <xsl:otherwise>low</xsl:otherwise>
                          </xsl:choose>
                        </xsl:attribute>
                        <xsl:value-of select="$p"/>
                      </span>
                    </td>
                    <td><xsl:value-of select="s:changefreq"/></td>
                    <td>
                      <xsl:choose>
                        <xsl:when test="s:lastmod"><xsl:value-of select="s:lastmod"/></xsl:when>
                        <xsl:otherwise><span class="muted">—</span></xsl:otherwise>
                      </xsl:choose>
                    </td>
                    <td class="num">
                      <xsl:variable name="n" select="count(image:image)"/>
                      <xsl:choose>
                        <xsl:when test="$n &gt; 0">
                          <span class="imgs"><xsl:value-of select="$n"/></span>
                        </xsl:when>
                        <xsl:otherwise><span class="muted">—</span></xsl:otherwise>
                      </xsl:choose>
                    </td>
                  </tr>
                </xsl:for-each>
              </tbody>
            </table>
          </div>
          <footer>
            Généré dynamiquement ·
            <a>
              <xsl:attribute name="href">
                <xsl:value-of select="s:url[1]/s:loc"/>
              </xsl:attribute>
              Retour au site
            </a>
          </footer>
        </div>
      </body>
    </html>
  </xsl:template>
</xsl:stylesheet>
