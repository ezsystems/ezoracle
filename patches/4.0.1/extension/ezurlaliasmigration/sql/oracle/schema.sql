
CREATE TABLE ezurlalias_ml_migrate (
    action varchar2(3000) NOT NULL,
    action_type varchar2(32) DEFAULT '' NOT NULL,
    alias_redirects integer DEFAULT 1 NOT NULL,
    extra_data varchar2(4000),
    id integer DEFAULT 0 NOT NULL,
    is_alias integer DEFAULT 0 NOT NULL,
    is_original integer DEFAULT 0 NOT NULL,
    is_restored integer DEFAULT 0 NOT NULL,
    lang_mask_adjusted integer DEFAULT 0 NOT NULL,
    lang_mask integer DEFAULT 0 NOT NULL,
    link integer DEFAULT 0 NOT NULL,
    parent integer DEFAULT 0 NOT NULL,
    text varchar2(3000) NOT NULL,
    text_md5 varchar2(32) DEFAULT '' NOT NULL
);

CREATE INDEX ezua_ml_migrate_act_org ON ezurlalias_ml_migrate (action, is_original);

CREATE INDEX ezua_ml_migrate_action ON ezurlalias_ml_migrate (action, id, link);

CREATE INDEX ezua_ml_migrate_actt ON ezurlalias_ml_migrate (action_type);

CREATE INDEX ezua_ml_migrate_actt_org_al ON ezurlalias_ml_migrate (action_type, is_original, is_alias);

CREATE INDEX ezua_ml_migrate_id ON ezurlalias_ml_migrate (id);

CREATE INDEX ezua_ml_migrate_par_act_id_lnk ON ezurlalias_ml_migrate (parent, action, id, link);

CREATE INDEX ezua_ml_migrate_par_lnk_txt ON ezurlalias_ml_migrate (parent, link, text);

CREATE INDEX ezua_ml_migrate_par_txt ON ezurlalias_ml_migrate (parent, text);

CREATE INDEX ezua_ml_migrate_text ON ezurlalias_ml_migrate (text, id, link);

CREATE INDEX ezua_ml_migrate_text_lang ON ezurlalias_ml_migrate (text, lang_mask, parent);

ALTER TABLE ezurlalias_ml_migrate
    ADD CONSTRAINT ezurlalias_ml_migrate_pkey PRIMARY KEY (parent, text_md5);
