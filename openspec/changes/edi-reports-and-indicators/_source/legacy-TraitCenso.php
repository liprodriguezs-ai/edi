<?php

namespace Censos\Controller;

trait TraitCenso
{

    public function wherePerfil()
    {
        switch ($this->session->id_perfil) {
            case 11:
                //Mandos medios
                $whereSQL = '';
                break;
            case 13:
                //Jurisdicción
                $whereSQL = " AND cat_municipios.jurisdiccion=(
                            SELECT jurisdiccion FROM cat_municipios
                            INNER JOIN cat_centros_salud ON cat_centros_salud.clave_municipio=cat_municipios.clave_municipio
                            WHERE cat_centros_salud.id_centro_salud = {$this->session->id_centro_salud}
                            and jurisdiccion !='')";
                break;
            default:
                //Médico y otros usuarios del centro de salud
                $whereSQL = "AND cat_centros_salud.id_centro_salud = {$this->session->id_centro_salud}";
                break;
        }

        return $whereSQL;
    }

    public function queryEmbarazada()
    {
        $adapter = $this->getService('Zend\Db\Adapter\Adapter');

        $where = $this->wherePerfil();

        $sql = "SELECT
        cat_municipios.municipio,
        cat_centros_salud.centro_salud,
        tran_beneficiarios.expediente,
        tran_beneficiarios.nombre,
        tran_beneficiarios.paterno,
        tran_beneficiarios.materno,
        DATE_FORMAT(tran_beneficiarios.fecha_nacimiento, '%d-%m-%Y') AS fecha_nacimiento,
        tran_beneficiarios.curp,
        tran_beneficiarios.folio_seguro_popular,
        tran_beneficiarios.folio_prospera,
        tran_beneficiarios.edad,
        tran_embarazada.domicilio,
        tran_embarazada.id_embarazada,
        tran_embarazada.fecha_inicio_control,
        tran_embarazada.trimestre,
        tran_embarazada.fecha_ultima_menstruacion,
        tran_embarazada.fecha_probable_parto,
        tran_citas_prenatales.fecha,
        CASE WHEN tran_citas_prenatales.semanas_gestacion < 15 THEN 1
        WHEN tran_citas_prenatales.semanas_gestacion BETWEEN 14 AND 26 THEN 2
        WHEN tran_citas_prenatales.semanas_gestacion >= 27 THEN 3
        END AS trimestre_actual,
        tran_embarazada.semanas_gestacion,
        tran_embarazada.trimestre,
        tran_embarazada.alto_riesgo,
        (SELECT resultado
        FROM tran_embarazada_anemia
        WHERE id_embarazada = tran_embarazada.id_embarazada AND trimestre = 1
        ORDER BY id_embarazada_anemia DESC LIMIT 1 ) AS hemoglobina_1,
        (SELECT resultado
        FROM tran_embarazada_anemia
        WHERE id_embarazada = tran_embarazada.id_embarazada AND trimestre = 2
        ORDER BY id_embarazada_anemia DESC LIMIT 1 ) AS hemoglobina_2,
        (SELECT resultado
        FROM tran_embarazada_anemia
        WHERE id_embarazada = tran_embarazada.id_embarazada AND trimestre = 3
        ORDER BY id_embarazada_anemia DESC LIMIT 1 ) AS hemoglobina_3,
        tran_citas_prenatales.suplemento_alimentaria
        FROM tran_embarazada
        INNER JOIN tran_beneficiarios ON tran_beneficiarios.id_beneficiario = tran_embarazada.id_beneficiario
        INNER JOIN cat_centros_salud ON cat_centros_salud.id_centro_salud = tran_beneficiarios.id_centro_salud
        INNER JOIN cat_municipios ON cat_municipios.clave_estado = cat_centros_salud.clave_estado AND cat_municipios.clave_municipio = cat_centros_salud.clave_municipio
        LEFT JOIN  tran_citas_prenatales ON tran_citas_prenatales.id_embarazada = tran_embarazada.id_embarazada
        WHERE tran_embarazada.fecha_baja IS NULL
        {$where}
        ORDER BY tran_embarazada.semanas_gestacion DESC
        ";

        return $adapter->query($sql, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);
    }

    public function queryPF()
    {
        $adapter = $this->getService('Zend\Db\Adapter\Adapter');

        $where = $this->wherePerfil();

        $sql = "SELECT PF.fecha_apertura_tarjeta,
                    cat_centros_salud.centro_salud,
                    tran_beneficiarios.nombre,
                    tran_beneficiarios.paterno,
                    tran_beneficiarios.materno,
                    tran_beneficiarios.folio_seguro_popular,
                    tran_beneficiarios.folio_prospera,
                    tran_beneficiarios.edad,
                    cat_metodo_planificacion.descripcion,
                    pfconsulta.fecha_proxima_cita
                FROM (
                    SELECT t1.id_beneficiario, t1.fecha_apertura_tarjeta
                    FROM tran_planificacion_familiar t1
                    WHERE t1.fecha_apertura_tarjeta = 
                    (
                        SELECT MAX(t2.fecha_apertura_tarjeta)
                        FROM tran_planificacion_familiar t2
                        WHERE t2.id_beneficiario = t1.id_beneficiario
                    )
                ) as PF
                INNER JOIN tran_beneficiarios ON tran_beneficiarios.id_beneficiario = PF.id_beneficiario
                INNER JOIN cat_centros_salud ON cat_centros_salud.id_centro_salud = tran_beneficiarios.id_centro_salud
                INNER JOIN cat_municipios ON cat_municipios.clave_estado = cat_centros_salud.clave_estado AND cat_municipios.clave_municipio = cat_centros_salud.clave_municipio
                LEFT JOIN (SELECT tran_pf_consulta.id_beneficiario, tran_pf_consulta.fecha_proxima_cita, tran_pf_consulta.metodo
                           FROM tran_pf_consulta ORDER BY tran_pf_consulta.id_tran_pf_consulta DESC LIMIT 1) pfconsulta
                           ON pfconsulta.id_beneficiario=PF.id_beneficiario
                LEFT JOIN cat_metodo_planificacion ON cat_metodo_planificacion.id_metodo_planificacion=pfconsulta.metodo  
                WHERE tran_beneficiarios.edad BETWEEN 15 AND 49
            {$where}
            ORDER BY PF.fecha_apertura_tarjeta DESC
        ";

        return $adapter->query($sql, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);
    }

    public function queryPuerperioLactancia()
    {
        $adapter = $this->getService('Zend\Db\Adapter\Adapter');

        $where = $this->wherePerfil();

        $sql = "SELECT
        cat_municipios.municipio,
        cat_centros_salud.centro_salud,
        tran_beneficiarios.expediente,
        tran_beneficiarios.nombre,
        tran_beneficiarios.paterno,
        tran_beneficiarios.materno,
        DATE_FORMAT(tran_beneficiarios.fecha_nacimiento, '%d-%m-%Y') AS fecha_nacimiento,
        tran_beneficiarios.curp,
        tran_beneficiarios.folio_seguro_popular,
        tran_beneficiarios.folio_prospera,
        tran_beneficiarios.edad,
        tran_embarazada.domicilio,
        tran_embarazada.fecha_inicio_control,
        (SELECT fecha_atencion
			FROM tran_puerperio
            WHERE tran_puerperio.beneficiario=tran_embarazada.id_beneficiario
            ORDER BY tran_puerperio.fecha_atencion DESC LIMIT 1) AS puerperio_fecha_atencion,
		(SELECT fecha_atencion
			FROM tran_lactancia
            WHERE tran_lactancia.beneficiario=tran_embarazada.id_beneficiario
            ORDER BY tran_lactancia.fecha_atencion DESC LIMIT 1) AS lactancia_fecha_atencion,
		(SELECT recibe_suplemento
			FROM tran_lactancia
            WHERE tran_lactancia.beneficiario=tran_embarazada.id_beneficiario
            ORDER BY tran_lactancia.fecha_atencion DESC LIMIT 1) AS lactancia_suplemento
        FROM tran_embarazada
        INNER JOIN tran_beneficiarios ON tran_beneficiarios.id_beneficiario = tran_embarazada.id_beneficiario
        INNER JOIN cat_centros_salud ON cat_centros_salud.id_centro_salud = tran_beneficiarios.id_centro_salud
        INNER JOIN cat_municipios ON cat_municipios.clave_estado = cat_centros_salud.clave_estado AND cat_municipios.clave_municipio = cat_centros_salud.clave_municipio
        INNER JOIN tran_embarazada_atencion ON tran_embarazada_atencion.id_embarazada = tran_embarazada.id_embarazada
        LEFT JOIN tran_puerperio ON tran_embarazada.id_beneficiario = tran_puerperio.beneficiario
        LEFT JOIN tran_lactancia ON tran_embarazada.id_beneficiario = tran_lactancia.beneficiario
        WHERE tran_lactancia.fecha_baja IS NULL
        {$where}
        GROUP BY tran_embarazada.id_beneficiario
        ORDER BY municipio,centro_salud,nombre";

        return $adapter->query($sql, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);
    }

    public function queryConcentradoEDI()
    {
        $adapter = $this->getService('Zend\Db\Adapter\Adapter');

        $sql = "SELECT clasificacion,  
        CONCAT(tran_usuarios.nombre, ' ', tran_usuarios.paterno, ' ', tran_usuarios.materno) AS aplicador,
        tran_edi_beneficiario.fecha_creacion, 
        tran_beneficiarios.nombre AS nombre_menor, 
        tran_beneficiarios.paterno AS paterno_menor, 
        tran_beneficiarios.materno AS materno_menor,
        tran_beneficiarios.expediente, 
        tran_beneficiarios.folio_prospera, 
        tran_beneficiarios.folio_seguro_popular,
        tran_beneficiarios.id_beneficiario, 
        DATE_FORMAT(tran_beneficiarios.fecha_nacimiento, '%d-%m-%Y') AS fecha_nacimiento,
        CONCAT(tran_beneficiarios.edad, ' años ', 
        tran_beneficiarios.edad_meses, ' meses ', 
        tran_beneficiarios.edad_dias, ' días') AS edad,
        tran_edi_beneficiario.semanas_gestacion, 
        tran_edi_beneficiario.edad_corregida, ((tran_beneficiarios.edad * 12) + tran_beneficiarios.edad_meses) AS edad_meses,
        tran_edi_beneficiario.clasificacion, 
        tran_edi_beneficiario.situacion,
        (SELECT calificacion_global FROM tran_edi_beneficiario teb WHERE teb.beneficiario=tran_edi_beneficiario.beneficiario  AND teb.fecha_creacion < tran_edi_beneficiario.fecha_creacion ORDER BY teb.fecha_creacion DESC LIMIT 1) AS semaforizacion_anterior,
        /*Obtener los ejes que salieron en amarillo o en rojo en una prueba anterior*/
        (SELECT 'FRB' FROM tran_edi_frb_respuestas tefr INNER JOIN tran_edi_beneficiario teb ON tefr.edi_beneficiario=teb.id_edi_beneficiario WHERE tefr.respuesta='SI' AND teb.beneficiario=tran_edi_beneficiario.beneficiario  AND teb.fecha_creacion < tran_edi_beneficiario.fecha_creacion ORDER BY teb.fecha_creacion DESC LIMIT 1) AS frb_anterior,
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'MG' AND teb.beneficiario=tran_edi_beneficiario.beneficiario  AND teb.fecha_creacion < tran_edi_beneficiario.fecha_creacion ORDER BY teb.fecha_creacion DESC LIMIT 1) AS 'MG_ANTERIOR',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'MF' AND teb.beneficiario=tran_edi_beneficiario.beneficiario  AND teb.fecha_creacion < tran_edi_beneficiario.fecha_creacion ORDER BY teb.fecha_creacion DESC LIMIT 1) AS 'MF_ANTERIOR',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'LE' AND teb.beneficiario=tran_edi_beneficiario.beneficiario  AND teb.fecha_creacion < tran_edi_beneficiario.fecha_creacion ORDER BY teb.fecha_creacion DESC LIMIT 1) AS 'LE_ANTERIOR',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'SO' AND teb.beneficiario=tran_edi_beneficiario.beneficiario  AND teb.fecha_creacion < tran_edi_beneficiario.fecha_creacion ORDER BY teb.fecha_creacion DESC LIMIT 1) AS 'SO_ANTERIOR',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'CO' AND teb.beneficiario=tran_edi_beneficiario.beneficiario  AND teb.fecha_creacion < tran_edi_beneficiario.fecha_creacion ORDER BY teb.fecha_creacion DESC LIMIT 1) AS 'CO_ANTERIOR',
        (SELECT SUBSTRING(eje, 1, 3) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 3) = 'ALA' AND teb.beneficiario=tran_edi_beneficiario.beneficiario  AND teb.fecha_creacion < tran_edi_beneficiario.fecha_creacion ORDER BY teb.fecha_creacion DESC LIMIT 1) AS 'ALA_ANTERIOR',
        (SELECT SUBSTRING(eje, 1, 3) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 3) = 'ALE' AND teb.beneficiario=tran_edi_beneficiario.beneficiario  AND teb.fecha_creacion < tran_edi_beneficiario.fecha_creacion ORDER BY teb.fecha_creacion DESC LIMIT 1) AS 'ALE_ANTERIOR',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'EN' AND teb.beneficiario=tran_edi_beneficiario.beneficiario  AND teb.fecha_creacion < tran_edi_beneficiario.fecha_creacion ORDER BY teb.fecha_creacion DESC LIMIT 1) AS 'EN_ANTERIOR',

        tran_edi_beneficiario.calificacion_global, tran_edi_beneficiario.textos_globales,

        /*Obtener los ejes que salieron en amarillo o en rojo*/
        (SELECT 'FRB' FROM tran_edi_frb_respuestas tefr INNER JOIN tran_edi_beneficiario teb ON tefr.edi_beneficiario=teb.id_edi_beneficiario WHERE tefr.respuesta='SI' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS frb,
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'MG' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'MG',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'MF' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'MF',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'LE' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'LE',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'SO' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'SO',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'CO' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'CO',
        (SELECT SUBSTRING(eje, 1, 3) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 3) = 'ALA' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'ALA',
        (SELECT SUBSTRING(eje, 1, 3) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 3) = 'ALE' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'ALE',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'EN' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'EN',

        CONCAT(madre.nombre, ' ', madre.paterno, ' ', madre.materno) AS madre_padron,
        tran_beneficiario_nacimiento.madre_nombres,
        tran_beneficiario_nacimiento.madre_paterno,
        tran_beneficiario_nacimiento.madre_materno,
        tran_beneficiario_nacimiento.madre_telefono, tran_beneficiario_nacimiento.madre_poblacion, tran_folio_recepcion.edad AS edad_anios,
        cat_tipo_beneficiario.tipo_beneficiario AS tipo_beneficiario,
        cat_servicio_complementario.servicio_complementario AS servicio_complementario,
        cat_estatus_beneficiario.estatus_beneficiario AS estatus_beneficiario,
        cat_proyecto.proyecto AS proyecto,
        cat_origen_referencia.origen_referencia AS origen_referencia,
        tran_signos_vitales.val_peso_talla AS val_peso_talla,
        tran_signos_vitales.val_peso_edad AS val_peso_edad,
        tran_signos_vitales.val_talla_edad AS val_talla_edad
        FROM tran_edi_beneficiario
        INNER JOIN tran_usuarios ON tran_edi_beneficiario.usuario_creo = tran_usuarios.id_usuario
        -- INNER JOIN (SELECT tebs.beneficiario, MAX(tebs.fecha_creacion) AS fechateb FROM tran_edi_beneficiario tebs GROUP BY tebs.beneficiario) AS tebm ON tran_edi_beneficiario.beneficiario=tebm.beneficiario AND tran_edi_beneficiario.fecha_creacion = tebm.fechateb
        -- INNER JOIN cat_centros_salud ON cat_centros_salud.id_centro_salud = tran_edi_beneficiario.centro_salud
        -- INNER JOIN cat_municipios ON cat_municipios.clave_estado = cat_centros_salud.clave_estado
            -- AND cat_municipios.clave_municipio = cat_centros_salud.clave_municipio
        INNER JOIN tran_beneficiarios ON tran_beneficiarios.id_beneficiario = tran_edi_beneficiario.beneficiario AND tran_beneficiarios.id_centro_salud=tran_edi_beneficiario.centro_salud
        INNER JOIN tran_folio_recepcion ON tran_folio_recepcion.id_folio_recepcion = tran_edi_beneficiario.folio_recepcion
        LEFT JOIN tran_beneficiario_nacimiento ON tran_beneficiario_nacimiento.id_beneficiario = tran_beneficiarios.id_beneficiario
        LEFT JOIN tran_beneficiarios madre ON tran_beneficiario_nacimiento.madre = madre.id_beneficiario
        LEFT JOIN cat_tipo_beneficiario ON cat_tipo_beneficiario.id_tipo_beneficiario = tran_beneficiarios.id_tipo_beneficiario
        LEFT JOIN cat_servicio_complementario ON cat_servicio_complementario.id_servicio_complementario = tran_beneficiarios.id_servicio_complementario
        LEFT JOIN cat_estatus_beneficiario ON cat_estatus_beneficiario.id_estatus_beneficiario = tran_beneficiarios.id_estatus_beneficiario
        LEFT JOIN cat_proyecto ON cat_proyecto.id_proyecto = tran_beneficiarios.id_proyecto
        LEFT JOIN cat_origen_referencia ON cat_origen_referencia.id_origen_referencia = tran_beneficiarios.id_origen_referencia
        LEFT JOIN tran_signos_vitales ON tran_signos_vitales.id_beneficiario = tran_edi_beneficiario.beneficiario
        
        WHERE tran_beneficiarios.estatus = 1 
        AND tran_edi_beneficiario.centro_salud = {$this->session->id_centro_salud} 
        ORDER BY tran_edi_beneficiario.fecha_creacion DESC";

        return $adapter->query($sql, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);

        //WHERE DATE_FORMAT(tran_edi_beneficiario.fecha_creacion, '%Y') = DATE_FORMAT(NOW(), '%Y')
    }
}
