<?php

namespace Indicador\Controller;

use Indicador\Model\IndicadorTable;
use Zend\Session\Container;
use Application\Controller\BaseController;
use Zend\View\Model\ViewModel;

class IndicadorController extends BaseController
{

    public function estadoNutricional()
    {
        $signosVitales = $this->getService(\Catalogos\Model\EstadoNutricionalIMCTable::class);
        $adapter = $this->getService('Zend\Db\Adapter\Adapter');
        $query = "SELECT *
        FROM tran_signos_vitales
        INNER JOIN tran_beneficiarios ON tran_beneficiarios.id_beneficiario = tran_signos_vitales.id_beneficiario
        INNER JOIN (SELECT id_beneficiario, max(id_signos_vitales) AS id_signos_vitales
        FROM tran_signos_vitales GROUP BY id_beneficiario) AS t ON tran_signos_vitales.id_signos_vitales = t.id_signos_vitales
        WHERE tran_signos_vitales.centro_salud = {$this->session->id_centro_salud}";

        $query = $adapter->query($query, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);
        $rows = $query->toArray();
      
        $return = [
            'pesoTalla' => [],
            'pesoEdad' => [],
            'tallaEdad' => []
        ];

    
        foreach ($rows as $key => $data) {
            if (empty($data['talla'])) {
                continue;
            }
            $data['peso'] = $data['peso_ent'];
            $data['edadMeses'] = ($data['edad'] * 12) + $data['edad_meses'];
            $dataPesoTalla =  $data;
            $dataPesoEdad =  $data;
            $dataTallaEdad =  $data;

            
            if ($data['edadMeses'] >= 0 && $data['edadMeses'] <= 23 && $data['genero'] == 'H') {
                $dataTallaEdad['grupo_etario'] = 9;
                $dataTallaEdad['edad'] = $data['edadMeses'];
                $dataTallaEdad['genero'] = 8;

                $dataPesoTalla['grupo_etario'] = 7;
                $dataPesoTalla['edad'] = $data['edadMeses'];
                $dataPesoTalla['genero'] = 8;

                $dataPesoEdad['grupo_etario'] = 11;
                $dataPesoEdad['edad'] = $data['edadMeses'];
                $dataPesoEdad['genero'] = 8;
            } elseif ($data['edadMeses'] >= 0 && $data['edadMeses'] <= 23 && $data['genero'] == 'M') {
                $dataTallaEdad['grupo_etario'] = 1;
                $dataTallaEdad['edad'] = $data['edadMeses'];
                $dataTallaEdad['genero'] = 7;

                $dataPesoTalla['grupo_etario'] = 3;
                $dataPesoTalla['edad'] = $data['edadMeses'];
                $dataPesoTalla['genero'] = 7;

                $dataPesoEdad['grupo_etario'] = 5;
                $dataPesoEdad['edad'] = $data['edadMeses'];
                $dataPesoEdad['genero'] = 7;
            } elseif (floor($data['edadMeses'] / 12) >= 2 && floor($data['edadMeses'] / 12) <= 5
                && $data['genero'] == 'H'
            ) {
                $dataTallaEdad['grupo_etario'] = 10;
                $dataTallaEdad['edad'] = $data['edadMeses'];
                $dataTallaEdad['genero'] = 8;

                $dataPesoTalla['grupo_etario'] = 8;
                $dataPesoTalla['edad'] = $data['edadMeses'];
                $dataPesoTalla['genero'] = 8;

                $dataPesoEdad['grupo_etario'] = 12;
                $dataPesoEdad['edad'] = $data['edadMeses'];
                $dataPesoEdad['genero'] = 8;
            } elseif (floor($data['edadMeses'] / 12) >= 2 && floor($data['edadMeses'] / 12) <= 5
                && $data['genero'] == 'M'
            ) {
                $dataTallaEdad['grupo_etario'] = 2;
                $dataTallaEdad['edad'] = $data['edadMeses'];
                $dataTallaEdad['genero'] = 7;

                $dataPesoTalla['grupo_etario'] = 4;
                $dataPesoTalla['edad'] = $data['edadMeses'];
                $dataPesoTalla['genero'] = 7;

                $dataPesoEdad['grupo_etario'] = 6;
                $dataPesoEdad['edad'] = $data['edadMeses'];
                $dataPesoEdad['genero'] = 7;
            }

            
            if (isset($dataPesoEdad['grupo_etario'])
                && isset($dataPesoTalla['grupo_etario'])
                && isset($dataPesoEdad['grupo_etario'])
            ) {
                $return['pesoTalla'][] = $signosVitales
                        ->getDiagnosticoNutricionalPesoTalla($dataPesoTalla);
                $return['pesoEdad' ][] = $signosVitales
                        ->getDiagnosticoNutricionalPesoEdad($dataPesoEdad);
                $return['tallaEdad'][] = $signosVitales
                        ->getDiagnosticoNutricionalTallaEdad($dataTallaEdad);
            }
        }
        $return = collect($return);
       
        $return['pesoTalla'] = collect($return['pesoTalla'])->groupBy('estado_nutricional');
        $return['pesoEdad'] = collect($return['pesoEdad'])->groupBy('estado_nutricional');
        $return['tallaEdad'] = collect($return['tallaEdad'])->groupBy('estado_nutricional');
    
        return $return;
    }

    public function indexAction()
    {
        setlocale(LC_TIME, 'spanish');
        $data = [
            'pruebasEdi' => $this->pruebasEdi(),
            'beneficiariosPorGrupo' => $this->beneficiariosPorGrupo(),
            'pruebasEdiResultado' => $this->pruebasEdiResultado(),
            'prubaPorArea' => $this->prubaPorArea(),
            'mes' => strftime("%B", mktime(0, 0, 0, date('m'), 1, 2000)),
            'colores' => [
                'AMARILLO' => 'warning',
                'ROJO' => 'danger',
                'VERDE' => 'success'
            ],
            'estadoNutricional' => $this->estadoNutricional()
        ];
        $this->layout('layout/layout');
        return new ViewModel([
            'data' => $data
        ]);
    }

    public function pruebasEdi()
    {

        $adapter = $this->getService('Zend\Db\Adapter\Adapter');
        $query = "SELECT count(1) AS 'pruebasEdi'
        FROM tran_edi_beneficiario WHERE MONTH(fecha_creacion) = MONTH(NOW())
        AND estatus = 1 AND centro_salud = {$this->session->id_centro_salud}";
        $query = $adapter->query($query, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);
        return $query->toArray()[0];
    }

    public function beneficiariosPorGrupo()
    {

        $adapter = $this->getService('Zend\Db\Adapter\Adapter');
        $query = "SELECT cat_grupo.id_grupo, cat_grupo.grupo, count(1) AS total
        FROM tran_beneficiarios
        INNER JOIN cat_grupo ON cat_grupo.id_grupo = tran_beneficiarios.id_grupo
        AND tran_beneficiarios.estatus = 1 
        WHERE tran_beneficiarios.id_centro_salud = {$this->session->id_centro_salud}
        GROUP BY cat_grupo.id_grupo, cat_grupo.grupo";
        $query = $adapter->query($query, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);
        return $query->toArray();
    }


    public function prubaPorArea()
    {
        $adapter = $this->getService('Zend\Db\Adapter\Adapter');
        $query = "SELECT 
        SUM(IF(FRB IS NOT NULL, 1, 0))AS 'FRB',
        SUM(IF(MG IS NOT NULL, 1, 0))AS 'MG',
        SUM(IF(MF IS NOT NULL, 1, 0))AS 'MF',
        SUM(IF(LE IS NOT NULL, 1, 0))AS 'LE',
        SUM(IF(SO IS NOT NULL, 1, 0))AS 'SO',
        SUM(IF(CO IS NOT NULL, 1, 0))AS 'CO',
        SUM(IF(ALA IS NOT NULL, 1, 0))AS 'ALA',
        SUM(IF(ALE IS NOT NULL, 1, 0))AS 'ALE',
        SUM(IF(EN IS NOT NULL, 1, 0))AS 'EN'
        
        FROM  (SELECT 
        (SELECT 'FRB' FROM tran_edi_frb_respuestas tefr INNER JOIN tran_edi_beneficiario teb ON tefr.edi_beneficiario=teb.id_edi_beneficiario WHERE tefr.respuesta='SI' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'FRB',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE teb.estatus = 1 AND color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'MG' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'MG',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE teb.estatus = 1 AND color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'MF' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'MF',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE teb.estatus = 1 AND color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'LE' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'LE',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE teb.estatus = 1 AND color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'SO' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'SO',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE teb.estatus = 1 AND color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'CO' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'CO',
        (SELECT SUBSTRING(eje, 1, 3) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE teb.estatus = 1 AND color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 3) = 'ALA' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'ALA',
        (SELECT SUBSTRING(eje, 1, 3) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE teb.estatus = 1 AND color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 3) = 'ALE' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'ALE',
        (SELECT SUBSTRING(eje, 1, 2) FROM tran_edi_evaluacion_respuestas teer INNER JOIN cat_edi_evaluacion cev ON cev.id_edi_evaluacion=teer.edi_evaluacion INNER JOIN tran_edi_beneficiario teb ON teb.id_edi_beneficiario=teer.edi_beneficiario WHERE teb.estatus = 1 AND color IN ('AMARILLO', 'ROJO') AND SUBSTRING(eje, 1, 2) = 'EN' AND teb.id_edi_beneficiario=tran_edi_beneficiario.id_edi_beneficiario LIMIT 1) AS 'EN'
        FROM tran_edi_beneficiario
        WHERE tran_edi_beneficiario.estatus = 1 AND centro_salud = {$this->session->id_centro_salud}) AS t";
        $query = $adapter->query($query, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);
        return $query->toArray()[0];
    }

    public function pruebasEdiResultado()
    {

        $adapter = $this->getService('Zend\Db\Adapter\Adapter');
        $query = "SELECT calificacion_global, count(1) AS 'pruebasEdi'
        FROM tran_edi_beneficiario 
        WHERE MONTH(fecha_creacion) = MONTH(NOW())
        AND estatus = 1 AND centro_salud = {$this->session->id_centro_salud}
        GROUP BY calificacion_global";
        $query = $adapter->query($query, \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);
        return $query->toArray();
    }
}
