UPDATE ezsite_data SET value='4.7.0beta1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

ALTER TABLE ezcontentobject_attribute ADD ( data_float_tmp BINARY_DOUBLE DEFAULT NULL );
UPDATE ezcontentobject_attribute SET data_float_tmp = data_float;
ALTER TABLE ezcontentobject_attribute DROP ( data_float );
ALTER TABLE ezcontentobject_attribute RENAME COLUMN data_float_tmp TO data_float;

ALTER TABLE ezcontentclass_attribute ADD ( data_float1_tmp BINARY_DOUBLE DEFAULT NULL );
ALTER TABLE ezcontentclass_attribute ADD ( data_float2_tmp BINARY_DOUBLE DEFAULT NULL );
ALTER TABLE ezcontentclass_attribute ADD ( data_float3_tmp BINARY_DOUBLE DEFAULT NULL );
ALTER TABLE ezcontentclass_attribute ADD ( data_float4_tmp BINARY_DOUBLE DEFAULT NULL );
UPDATE ezcontentclass_attribute SET data_float1_tmp = data_float1, data_float2_tmp = data_float2, data_float3_tmp = data_float3, data_float4_tmp = data_float4;
ALTER TABLE ezcontentclass_attribute DROP ( data_float1, data_float2, data_float3, data_float4 );
ALTER TABLE ezcontentclass_attribute RENAME COLUMN data_float1_tmp TO data_float1;
ALTER TABLE ezcontentclass_attribute RENAME COLUMN data_float2_tmp TO data_float2;
ALTER TABLE ezcontentclass_attribute RENAME COLUMN data_float3_tmp TO data_float3;
ALTER TABLE ezcontentclass_attribute RENAME COLUMN data_float4_tmp TO data_float4;
