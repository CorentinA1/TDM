<?php
/**
 * lib/schema.php — tables propres du module TDM.
 *
 * Créées à la première ouverture d'une page qui en a besoin (jamais depuis
 * menu.php). Les colonnes de compétition sont en INT UNSIGNED comme
 * Tournament.ToId : plus étroites, MySQL tronquerait l'identifiant en silence
 * (leçon du module TNM, version 1.0.20).
 *
 * TdmBsoTeams : équipes confirmées pour le BSO d'une épreuve, dans l'ordre des
 *               cibles (BtPos) ; BtTarget = cible attribuée.
 * TdmBsoEnds  : une ligne par équipe et par volée : score (0 à 20) et ordre
 *               saisi pour trancher une égalité (1 = devant).
 */
function tdm_schema()
{
    static $done = false;
    if ($done) return;
    $done = true;

    safe_w_sql("CREATE TABLE IF NOT EXISTS TdmBsoTeams (
        BtTournament INT UNSIGNED NOT NULL,
        BtEvent      VARCHAR(10)  NOT NULL,
        BtCoId       INT          NOT NULL,
        BtSubTeam    TINYINT      NOT NULL DEFAULT 0,
        BtPos        TINYINT      NOT NULL,
        BtTarget     SMALLINT     NULL,
        BtCreated    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (BtTournament, BtEvent, BtCoId, BtSubTeam)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    safe_w_sql("CREATE TABLE IF NOT EXISTS TdmBsoEnds (
        BeTournament INT UNSIGNED NOT NULL,
        BeEvent      VARCHAR(10)  NOT NULL,
        BeRound      TINYINT      NOT NULL,
        BeCoId       INT          NOT NULL,
        BeSubTeam    TINYINT      NOT NULL DEFAULT 0,
        BeScore      TINYINT      NULL,
        BeOrder      TINYINT      NULL,
        BeBy         VARCHAR(64)  NOT NULL DEFAULT '',
        BeUpdated    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (BeTournament, BeEvent, BeRound, BeCoId, BeSubTeam)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
