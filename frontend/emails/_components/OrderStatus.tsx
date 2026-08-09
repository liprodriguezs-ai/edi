import React from 'react'
import { Container, Row, Column, Section, Text } from '@react-email/components'
import { BladeTrans, Blade } from './LaravelBlade'

interface OrderStatusProps {
  status: 'pending' | 'processing' | 'shipped' | 'delivered' | 'cancelled';
  statusDate?: string;
  estimatedDelivery?: string;
  trackingNumber?: string;
  trackingUrl?: string;
  primaryColor?: string;
}

export const OrderStatus = ({
  status = 'processing',
  statusDate = 'April 24, 2024',
  estimatedDelivery,
  trackingNumber,
  trackingUrl,
  primaryColor = '#e67e22'
}: OrderStatusProps) => {
  
  // Status definitions
  const statuses = [
    { key: 'pending', icon: '🕒', label: <BladeTrans translationKey="order.status.pending">Pendiente</BladeTrans> },
    { key: 'processing', icon: '⚙️', label: <BladeTrans translationKey="order.status.processing">En proceso</BladeTrans> },
    { key: 'shipped', icon: '📦', label: <BladeTrans translationKey="order.status.shipped">Enviado</BladeTrans> },
    { key: 'delivered', icon: '✅', label: <BladeTrans translationKey="order.status.delivered">Entregado</BladeTrans> }
  ];

  // Find current status index (for progress bar)
  const currentStatusIndex = statuses.findIndex(s => s.key === status);
  const isCancelled = status === 'cancelled';
  
  // Status-specific details
  const getStatusMessage = () => {
    switch (status) {
      case 'pending':
        return <BladeTrans translationKey="order.status.pending_message">Tu pedido está pendiente de confirmación.</BladeTrans>;
      case 'processing':
        return <BladeTrans translationKey="order.status.processing_message">Tu pedido está siendo preparado.</BladeTrans>;
      case 'shipped':
        return trackingNumber 
          ? <BladeTrans translationKey="order.status.shipped_tracking_message">Tu pedido ha sido enviado. Puedes seguir el envío con el número de seguimiento.</BladeTrans>
          : <BladeTrans translationKey="order.status.shipped_message">Tu pedido ha sido enviado.</BladeTrans>;
      case 'delivered':
        return <BladeTrans translationKey="order.status.delivered_message">Tu pedido ha sido entregado.</BladeTrans>;
      case 'cancelled':
        return <BladeTrans translationKey="order.status.cancelled_message">Este pedido ha sido cancelado.</BladeTrans>;
      default:
        return <BladeTrans translationKey="order.status.default_message">Estado del pedido actualizado.</BladeTrans>;
    }
  };

  return (
    <Section className="py-4">
      <Container className="border border-solid border-primary/20 rounded-lg p-4 bg-white">
        {/* Status header */}
        <Row className="mb-4">
          <Column>
            <Text className="text-[18px] font-bold text-[#333]">
              <BladeTrans translationKey="order.status.title">Estado del pedido</BladeTrans>
            </Text>
          </Column>
          <Column align="right">
            <Text className="text-[14px] text-gray-500">
              <BladeTrans translationKey="order.status.updated">Actualizado</BladeTrans>: <Blade variable="order->updated_date" fallback={statusDate} />
            </Text>
          </Column>
        </Row>
        
        {/* Order message */}
        <Text className="text-[15px] text-[#333] mb-4">
          {getStatusMessage()}
        </Text>

        {/* Progress tracker - Hide if cancelled */}
        {!isCancelled && (
          <Container className="mb-4 mt-2">
            {/* Status indicators */}
            <Row>
              {statuses.map((s, index) => (
                <Column key={s.key} align="center" className="w-1/4">
                  <Container 
                    className="rounded-full mx-auto flex items-center justify-center"
                    style={{ 
                      width: '40px', 
                      height: '40px',
                      backgroundColor: index <= currentStatusIndex ? primaryColor : '#f0f0f0',
                      color: index <= currentStatusIndex ? 'white' : '#999'
                    }}
                  >
                    <Text className="text-[16px] m-0">{s.icon}</Text>
                  </Container>
                  <Text 
                    className="text-[12px] mt-1 text-center" 
                    style={{ 
                      fontWeight: index <= currentStatusIndex ? 'bold' : 'normal',
                      color: index <= currentStatusIndex ? primaryColor : '#999'
                    }}
                  >
                    {s.label}
                  </Text>
                </Column>
              ))}
            </Row>
            
            {/* Connecting lines */}
            <Row>
              <Column className="pt-[20px]">
                <Container 
                  style={{ 
                    height: '2px', 
                    backgroundColor: currentStatusIndex >= 1 ? primaryColor : '#f0f0f0'
                  }}
                />
              </Column>
              <Column className="pt-[20px]">
                <Container 
                  style={{ 
                    height: '2px', 
                    backgroundColor: currentStatusIndex >= 2 ? primaryColor : '#f0f0f0'
                  }}
                />
              </Column>
              <Column className="pt-[20px]">
                <Container 
                  style={{ 
                    height: '2px', 
                    backgroundColor: currentStatusIndex >= 3 ? primaryColor : '#f0f0f0'
                  }}
                />
              </Column>
            </Row>
          </Container>
        )}
        
        {/* Cancelled status */}
        {isCancelled && (
          <Container className="bg-[#ffebee] p-3 rounded mb-4">
            <Row>
              <Column className="w-[30px]">
                <Text className="text-[24px] m-0">❌</Text>
              </Column>
              <Column>
                <Text className="text-[16px] font-bold text-[#e74c3c] m-0">
                  <BladeTrans translationKey="order.status.cancelled">Pedido cancelado</BladeTrans>
                </Text>
                <Text className="text-[14px] text-[#333] m-0">
                  <BladeTrans translationKey="order.status.cancelled_details">Este pedido ha sido cancelado. Si tienes dudas, contáctanos.</BladeTrans>
                </Text>
              </Column>
            </Row>
          </Container>
        )}
        
        {/* Shipment information if available */}
        {(trackingNumber || estimatedDelivery) && (status === 'shipped' || status === 'delivered') && (
          <Container className="bg-[#f9f9f9] p-3 rounded rounded-lg">
            {estimatedDelivery && (
              <Text className="text-[14px] text-[#333] mb-2">
                <strong><BladeTrans translationKey="order.status.estimated_delivery">Entrega estimada</BladeTrans>:</strong> <Blade variable="order->estimated_delivery" fallback={estimatedDelivery} />
              </Text>
            )}
            
            {trackingNumber && (
              <Text className="text-[14px] text-[#333]">
                <strong><BladeTrans translationKey="order.status.tracking_number">Número de seguimiento</BladeTrans>:</strong>{' '}
                {trackingUrl ? (
                  <a href={trackingUrl} style={{ color: primaryColor, textDecoration: 'none' }}>
                    <Blade variable="order->tracking_number" fallback={trackingNumber} />
                  </a>
                ) : <Blade variable="order->tracking_number" fallback={trackingNumber} />}
              </Text>
            )}
          </Container>
        )}
      </Container>
    </Section>
  );
};

export default OrderStatus; 